import { defineStore } from 'pinia'
import { api, ApiError } from '../api/client'
import { useAuthStore } from './auth'
import { ensureFrontMatter } from '../util/frontMatterTemplate'
import { useReviewStore } from './review'

const MARKDOWN_RE = /\.(md|markdown)$/i

const VIEW_KEY = 'hugocms_view'

// Eigene Obergrenzen je Upload-Anfrage. Sie halten die einzelne Anfrage klein
// (Laufzeit der Bildumwandlung, Grenzen vorgeschalteter Webserver) und greifen
// zusätzlich zu dem, was der Server über whoami meldet (uploadLimits).
const UPLOAD_BATCH_FILES = 10
const UPLOAD_BATCH_BYTES = 16 * 1024 * 1024
// Abzug von post_max_size für Formularfelder und die Kopfzeilen je Datei.
const UPLOAD_POST_RESERVE = 64 * 1024
const UPLOAD_PART_OVERHEAD = 1024

// Teilt Dateien in Anfragen auf, die Anzahl- und Größengrenzen einhalten. Eine
// Datei, die allein schon über der Größengrenze liegt, bekommt eine eigene
// Anfrage — ob sie durchgeht, entscheidet der Server.
function planUploadBatches(list, limits) {
  const maxFiles = limits.maxFiles > 0 ? Math.min(limits.maxFiles, UPLOAD_BATCH_FILES) : UPLOAD_BATCH_FILES
  const maxBytes = limits.postBytes > 0
    ? Math.max(1, Math.min(limits.postBytes - UPLOAD_POST_RESERVE, UPLOAD_BATCH_BYTES))
    : UPLOAD_BATCH_BYTES
  const batches = []
  let batch = []
  let bytes = 0
  for (const f of list) {
    const size = f.size + UPLOAD_PART_OVERHEAD
    if (batch.length && (batch.length >= maxFiles || bytes + size > maxBytes)) {
      batches.push(batch)
      batch = []
      bytes = 0
    }
    batch.push(f)
    bytes += size
  }
  if (batch.length) batches.push(batch)
  return batches
}

// Hat der Webserver die Anfrage wegen ihrer Größe abgewiesen? HTTP 413 kommt
// ohne JSON zurück (ENETWORK); manche Server kappen stattdessen die Verbindung,
// dann scheitert fetch selbst (TypeError).
function isHttp413(e) {
  return e instanceof ApiError && e.code === 'ENETWORK' && e.params?.[0] === '413'
}

function readView() {
  try {
    const v = localStorage.getItem(VIEW_KEY)
    if (v === 'list' || v === 'icons') return v
  } catch {
    // ignorieren
  }
  return 'list'
}

const SORT_KEY = 'hugocms_sort'
const SORT_COLUMNS = ['name', 'size', 'type', 'ctime', 'mtime']

function readSort() {
  try {
    const v = JSON.parse(localStorage.getItem(SORT_KEY) ?? 'null')
    if (v && SORT_COLUMNS.includes(v.key) && (v.dir === 'asc' || v.dir === 'desc')) {
      return { key: v.key, dir: v.dir }
    }
  } catch {
    // ignorieren
  }
  return { key: 'name', dir: 'asc' }
}

const nameCollator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' })

// Sortierschlüssel der Typ-Spalte: die Dateiendung, ersatzweise der MIME-Typ —
// dieselbe Grundlage wie die Anzeige in der Spalte.
function typeKey(e) {
  const dot = e.name.lastIndexOf('.')
  if (dot > 0 && dot < e.name.length - 1) return e.name.slice(dot + 1).toLowerCase()
  return (e.mime || '').toLowerCase()
}

// Sortiert eine Kopie der Liste. Ordner stehen unabhängig von Spalte und
// Richtung vor den Dateien; bei Gleichstand entscheidet der Name aufsteigend.
function sortEntries(list, { key, dir }) {
  const sign = dir === 'desc' ? -1 : 1
  const compare = (a, b) => {
    if (key === 'size') return (a.size ?? 0) - (b.size ?? 0)
    if (key === 'ctime' || key === 'mtime') return (a[key] ?? 0) - (b[key] ?? 0)
    if (key === 'type') return typeKey(a).localeCompare(typeKey(b))
    return 0
  }
  return [...list].sort((a, b) => {
    const aDir = a.type === 'dir'
    const bDir = b.type === 'dir'
    if (aDir !== bDir) return aDir ? -1 : 1
    // Ordner haben weder Größe noch Typ — sie bleiben dort nach Namen geordnet.
    const primary = aDir && (key === 'size' || key === 'type') ? 0 : compare(a, b) * sign
    if (primary !== 0) return primary
    const byName = nameCollator.compare(a.name, b.name)
    return key === 'name' ? byName * sign : byName
  })
}

export const useFilesStore = defineStore('files', {
  state: () => ({
    mounts: [],
    activeMount: null, // Name des aktiven Mounts
    cwd: null, // Metadaten des aktuellen Verzeichnisses
    entries: [], // Inhalt des aktuellen Verzeichnisses
    breadcrumb: [], // [{ id, name }] von der Mount-Wurzel bis cwd
    loading: false,

    view: readView(), // 'list' | 'icons'
    filter: '', // Schnellfilter (Namensteil) auf das aktuelle Verzeichnis
    sort: readSort(), // { key: 'name'|'size'|'type'|'ctime'|'mtime', dir: 'asc'|'desc' }

    selectedIds: [], // markierte Einträge (Mehrfachauswahl)
    selectionAnchor: null, // Anker für die Bereichsauswahl (Shift)
    clipboard: { mode: null, ids: [] }, // mode: 'copy' | 'cut' | null

    newRequest: null, // 'folder' | 'file' | 'upload' — von der Toolbar gesetzt, vom Browser konsumiert
    uploading: false, // läuft gerade ein Upload? (Fortschrittsanzeige)
    uploadProgress: null, // { done, total } während eines Uploads, sonst null
    viewerId: null, // im Bildbetrachter geöffneter Eintrag (oder null)
    editImageId: null, // im Bild-Editor geöffneter Eintrag (oder null)

    // Erweiterte Suche (Stufe 4) nach Nemo-Vorbild: eine eigene, ausklappbare
    // Suchleiste mit Dateiname (Teilstring oder Muster mit */?) und/oder Text
    // im Inhalt — getrennt vom Schnellfilter (filter), der nur das angezeigte
    // Verzeichnis beim Tippen eingrenzt. Gesucht wird im ganzen Ort oder, auf
    // Wunsch, nur ab dem aktuellen Ordner. Ergebnisse gelten als aktiv, solange
    // eines der beiden Kriterien gesetzt ist (Getter searchActive).
    searchOpen: false, // Suchleiste sichtbar
    searchName: '', // Eingabefeld Dateiname/Muster
    contentText: '', // Eingabefeld Inhaltstext
    searchFromCwd: false, // nur ab aktuellem Ordner statt im ganzen Ort
    searchQuery: '', // Namenskriterium der laufenden Ergebnisanzeige
    searchText: '', // Inhaltskriterium der laufenden Ergebnisanzeige
    searchScopeCwd: false, // Ergebnisanzeige stammt aus einer Suche ab cwd
    searchResults: [],
    searchTruncated: false,
    searchBusy: false, // Lauf läuft (Inhaltssuche liest Dateien, dauert spürbar)
    // Entwurfsfilter: zeigt ab dem aktuellen Verzeichnis alle Content-Dateien
    // mit `draft: true` im Front Matter. Nutzt dieselbe Ergebnisliste wie die
    // Suche — die beiden schließen einander aus.
    draftFilter: false,
    draftBusy: false, // Lauf läuft (Front Matter jeder Datei wird gelesen)
    // Fehler des Entwurfsfilters. Ausgelöst wird er in der Werkzeugleiste,
    // angezeigt im Dateimanager — deshalb der Umweg über den Store.
    searchError: null,

    // Papierkorb-Ansicht (Stufe 4).
    trashMode: false,
    trashEntries: [],
    trashSelected: [], // [{mount, trashName}]

    // SEO-Audit-Ansicht (Pro-Funktion). Wie trashMode eine Vollbild-Ansicht im
    // Hauptbereich; die eigentlichen Audit-Daten liegen im audit-Store.
    auditMode: false,

    // Navigationsverlauf (wie Nemo Zurück/Vor). Jeder Eintrag ist eine
    // Momentaufnahme { id, breadcrumb, activeMount }.
    history: [],
    historyIndex: -1,

    openFile: null, // { id, name, content, mtime, path } oder null
    dirty: false, // ungespeicherte Änderungen im Editor
    reloadTick: 0, // erhöht sich, wenn der offene Inhalt extern neu geladen wurde
  }),

  getters: {
    canBack: (s) => s.historyIndex > 0,
    canForward: (s) => s.historyIndex < s.history.length - 1,
    canUp: (s) => s.breadcrumb.length > 1,
    // Schnellfilter auf das aktuelle Verzeichnis. Mit * oder ? gilt die
    // Eingabe als Muster — dieselbe Regel wie bei der Serversuche, damit das
    // Feld vor und nach Enter gleich reagiert.
    visibleEntries: (s) => {
      const q = s.filter.trim().toLowerCase()
      let list = s.entries
      if (q !== '') {
        if (/[*?]/.test(q)) {
          const re = new RegExp('^' + q.replace(/[.+^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*').replace(/\?/g, '.') + '$')
          list = list.filter((e) => re.test(e.name.toLowerCase()))
        } else {
          list = list.filter((e) => e.name.toLowerCase().includes(q))
        }
      }
      return sortEntries(list, s.sort)
    },
    searchActive: (s) => s.searchQuery !== '' || s.searchText !== '',
    isSelected: (s) => (id) => s.selectedIds.includes(id),
    selectedCount: (s) => s.selectedIds.length,
    hasClipboard: (s) => s.clipboard.ids.length > 0,
    // Bilder des aktuellen Verzeichnisses (Reihenfolge = Anzeige) für den Betrachter.
    imageEntries: (s) => s.entries.filter((e) => e.image),
    viewerEntry(state) {
      return state.viewerId ? this.imageEntries.find((e) => e.id === state.viewerId) ?? null : null
    },
    viewerIndex(state) {
      return state.viewerId ? this.imageEntries.findIndex((e) => e.id === state.viewerId) : -1
    },
    // Im Bild-Editor geöffneter Eintrag (aus dem aktuellen Verzeichnis).
    editImageEntry: (s) => (s.editImageId ? s.entries.find((e) => e.id === s.editImageId) ?? null : null),
    // Rechte des aktiven Mounts (für Menü-/Toolbar-Aktivierung).
    can: (s) => (perm) => {
      const m = s.mounts.find((mm) => mm.name === s.activeMount)
      return m ? m.permissions.includes(perm) : false
    },
  },

  actions: {
    async loadMounts() {
      const data = await api.get('mounts')
      this.mounts = data.mounts
      // Wie Nemo direkt in einem Ort starten: den ersten Mount öffnen.
      if (!this.cwd && this.mounts.length > 0) {
        await this.openDir(this.mounts[0].id)
      }
    },

    async _list(id) {
      this.loading = true
      try {
        const data = await api.get('list', { target: id })
        this.cwd = data.cwd
        this.entries = data.entries
        this.selectedIds = []
        this.selectionAnchor = null
        this.filter = ''
        this.leaveSearch()
        return data
      } finally {
        this.loading = false
      }
    },

    async openDir(id, label = null) {
      this.trashMode = false
      this.auditMode = false
      const data = await this._list(id)

      const mount = this.mounts.find((m) => m.id === id)
      if (mount) {
        this.activeMount = mount.name
        this.breadcrumb = [{ id, name: mount.label }]
      } else {
        const existing = this.breadcrumb.findIndex((b) => b.id === id)
        if (existing >= 0) {
          this.breadcrumb = this.breadcrumb.slice(0, existing + 1)
        } else {
          this.breadcrumb.push({ id, name: label ?? data.cwd.name })
        }
      }
      this._pushHistory(id)
    },

    _pushHistory(id) {
      this.history = this.history.slice(0, this.historyIndex + 1)
      this.history.push({
        id,
        breadcrumb: this.breadcrumb.map((b) => ({ ...b })),
        activeMount: this.activeMount,
      })
      this.historyIndex = this.history.length - 1
    },

    async _restore(snapshot) {
      await this._list(snapshot.id)
      this.breadcrumb = snapshot.breadcrumb.map((b) => ({ ...b }))
      this.activeMount = snapshot.activeMount
    },

    async goBack() {
      if (!this.canBack) return
      this.historyIndex--
      await this._restore(this.history[this.historyIndex])
    },

    async goForward() {
      if (!this.canForward) return
      this.historyIndex++
      await this._restore(this.history[this.historyIndex])
    },

    async goUp() {
      if (!this.canUp) return
      await this.openDir(this.breadcrumb[this.breadcrumb.length - 2].id)
    },

    async refresh() {
      const current = this.history[this.historyIndex]
      if (current) await this._restore(current)
    },

    setView(view) {
      if (view !== 'list' && view !== 'icons') return
      this.view = view
      try {
        localStorage.setItem(VIEW_KEY, view)
      } catch {
        // ignorieren
      }
    },

    // Klick auf eine Spaltenüberschrift: dieselbe Spalte kehrt die Richtung um,
    // eine andere Spalte beginnt aufsteigend.
    toggleSort(key) {
      if (!SORT_COLUMNS.includes(key)) return
      const dir = this.sort.key === key && this.sort.dir === 'asc' ? 'desc' : 'asc'
      this.sort = { key, dir }
      try {
        localStorage.setItem(SORT_KEY, JSON.stringify(this.sort))
      } catch {
        // ignorieren
      }
    },

    setFilter(value) {
      this.filter = value
    },

    setSearchName(value) {
      this.searchName = value
    },

    setContentText(value) {
      this.contentText = value
    },

    setSearchFromCwd(value) {
      this.searchFromCwd = !!value
    },

    // Suchleiste ein-/ausblenden. Beim Ausblenden endet eine laufende
    // Ergebnisanzeige (siehe exitSearch), die Eingaben werden verworfen.
    async toggleSearchBar() {
      if (this.searchOpen) {
        await this.exitSearch()
      } else {
        this.searchOpen = true
      }
    },

    requestNew(kind) {
      this.newRequest = kind
    },

    // --- Auswahl ---------------------------------------------------------

    selectOnly(id) {
      this.selectedIds = id == null ? [] : [id]
      this.selectionAnchor = id
    },

    toggleSelect(id) {
      const i = this.selectedIds.indexOf(id)
      if (i >= 0) this.selectedIds.splice(i, 1)
      else this.selectedIds.push(id)
      this.selectionAnchor = id
    },

    selectRange(id) {
      const list = this.visibleEntries.map((e) => e.id)
      const a = this.selectionAnchor ? list.indexOf(this.selectionAnchor) : 0
      const b = list.indexOf(id)
      if (a < 0 || b < 0) {
        this.selectOnly(id)
        return
      }
      const [lo, hi] = a <= b ? [a, b] : [b, a]
      this.selectedIds = list.slice(lo, hi + 1)
    },

    clearSelection() {
      this.selectedIds = []
      this.selectionAnchor = null
    },

    // --- Zwischenablage --------------------------------------------------

    copySelection() {
      if (this.selectedIds.length) this.clipboard = { mode: 'copy', ids: [...this.selectedIds] }
    },

    cutSelection() {
      if (this.selectedIds.length) this.clipboard = { mode: 'cut', ids: [...this.selectedIds] }
    },

    async paste() {
      if (!this.cwd || this.clipboard.ids.length === 0) return
      const { mode, ids } = this.clipboard
      const cmd = mode === 'cut' ? 'move' : 'copy'
      await api.post(cmd, { sources: ids, dest: this.cwd.id })
      if (mode === 'cut') this.clipboard = { mode: null, ids: [] }
      await this.refresh()
    },

    // --- Operationen (Stufe 2) ------------------------------------------

    async createFolder(name) {
      await api.post('mkdir', { target: this.cwd.id, name })
      await this.refresh()
    },

    async createFile(name) {
      const meta = await api.post('newfile', { target: this.cwd.id, name })
      // Neue Markdown-Datei im Hugo-Content-Ordner (vom Backend als contentFile
      // gemeldet): das Front-Matter-Template gleich hineinschreiben, damit die
      // Datei schon angelegt die Metadaten trägt.
      if (meta?.contentFile && MARKDOWN_RE.test(name)) {
        const { content } = ensureFrontMatter('', name)
        await api.post('write', { target: meta.id, content })
      }
      await this.refresh()
      // Eine für den Editor geeignete Datei (editierbare Endung, dem Konto
      // erlaubt — beides meldet entryInfo) gleich öffnen: Wer sie anlegt,
      // will sie in aller Regel auch füllen.
      if (meta?.editable) await this.openTextFile(meta)
    },

    async renameEntry(id, name) {
      await api.post('rename', { target: id, name })
      await this.refresh()
    },

    // Name bewusst nicht `trashEntries`: So heißt bereits das State-Feld mit der
    // Papierkorb-Liste. Pinia führt State und Actions auf demselben Objekt
    // zusammen, sodass ein gleichnamiges Paar sich gegenseitig überschreibt.
    async moveToTrash(ids) {
      if (!ids.length) return
      await api.post('delete', { targets: ids })
      await this.refresh()
    },

    // --- Stufe 4: Suche und Papierkorb ------------------------------------

    // Serversuche im ganzen Ort (Mount-Wurzel) oder ab dem aktuellen Ordner.
    // Beide Kriterien stammen aus der Suchleiste; der Server verlangt für
    // jedes angegebene mindestens zwei Zeichen und mindestens eines von beiden.
    async search() {
      const q = this.searchName.trim()
      const text = this.contentText.trim()
      const root = this.searchFromCwd ? this.cwd : this.breadcrumb[0]
      if (!root || this.searchBusy || (q === '' && text === '')) return
      this.searchBusy = true
      this.searchError = null
      try {
        const data = await api.get('search', { target: root.id, q, text })
        this.searchQuery = data.query
        this.searchText = data.text
        this.searchScopeCwd = this.searchFromCwd
        this.draftFilter = false // Suche und Entwurfsfilter schließen einander aus
        this.searchResults = data.entries
        this.searchTruncated = data.truncated
        this.selectedIds = []
        this.selectionAnchor = null
      } catch (e) {
        this.searchError = e
      } finally {
        this.searchBusy = false
      }
    },

    // Alle nicht veröffentlichten Seiten (draft: true) ab dem aktuellen
    // Verzeichnis. Die Treffer landen in derselben Liste wie Suchergebnisse,
    // die Ansicht unterscheidet sie über draftFilter.
    async searchDrafts() {
      if (!this.cwd || this.draftBusy) return
      this.draftBusy = true
      this.searchError = null
      try {
        const data = await api.get('draftsearch', { target: this.cwd.id })
        this.searchQuery = ''
        this.searchText = ''
        this.draftFilter = true
        this.searchResults = data.entries
        this.searchTruncated = data.truncated
        this.selectedIds = []
        this.selectionAnchor = null
      } catch (e) {
        this.searchError = e
      } finally {
        this.draftBusy = false
      }
    },

    // Räumt NUR den Suchzustand ab. Für den Rückweg in die Ordneransicht ist
    // exitSearch() zuständig — hier bleiben Filtertext und Liste unberührt,
    // weil auch _list(), openTrash() und openAudit() diese Aktion nutzen.
    leaveSearch() {
      this.searchQuery = ''
      this.searchText = ''
      this.draftFilter = false
      this.searchResults = []
      this.searchTruncated = false
    },

    /**
     * Zurück aus Suchergebnissen oder Entwurfsfilter in die Ordneransicht:
     * Suchzustand abräumen, die Suchleiste schließen und ihre Eingaben leeren,
     * dann das Verzeichnis frisch laden, weil sich währenddessen etwas
     * geändert haben kann. Der Schnellfilter bleibt unberührt — er gehört zur
     * Ordneransicht, nicht zur Suche.
     */
    async exitSearch() {
      this.leaveSearch()
      this.searchOpen = false
      this.searchName = ''
      this.contentText = ''
      await this.refresh()
    },

    async openTrash() {
      this.trashMode = true
      this.auditMode = false
      this.leaveSearch()
      await this.loadTrash()
    },

    leaveTrash() {
      this.trashMode = false
      this.trashEntries = []
      this.trashSelected = []
    },

    // --- SEO-Audit-Ansicht ----------------------------------------------

    openAudit() {
      this.auditMode = true
      this.trashMode = false
      this.leaveSearch()
    },

    leaveAudit() {
      this.auditMode = false
    },

    // Öffnet eine Datei direkt über ihre (undurchsichtige) Dateimanager-ID —
    // genutzt vom Audit, um aus einem Fund zur Quelldatei zu springen. Der
    // Audit-Modus bleibt dabei aktiv, sodass der Editor sich nur überlagert und
    // beim Schließen der Bericht wieder erscheint (siehe AuditView.openSource).
    async openFileById(id) {
      const data = await api.get('read', { target: id })
      this.openFile = { id, name: data.name, content: data.content, mtime: data.mtime, path: data.path, contentFile: !!data.contentFile }
      this.dirty = false
    },

    async loadTrash() {
      const data = await api.get('trashlist')
      this.trashEntries = data.entries
      this.trashSelected = []
    },

    async restoreTrash(items) {
      // Je Mount bündeln (das Backend stellt mount-weise wieder her).
      const byMount = new Map()
      for (const it of items) {
        if (!byMount.has(it.mount)) byMount.set(it.mount, [])
        byMount.get(it.mount).push(it.trashName)
      }
      for (const [mount, names] of byMount) {
        await api.post('restore', { mount, names })
      }
      await this.loadTrash()
      if (this.cwd) await this.refresh()
    },

    async emptyTrash() {
      await api.post('emptytrash')
      await this.loadTrash()
    },

    // --- Stufe 3: Upload, Download, Bildbetrachter -----------------------

    // format (webp, jpg, png, avif): Rasterbilder werden beim Ablegen auf dem
    // Server in dieses Format umgewandelt; leer = unverändert speichern.
    // Lädt Dateien in das aktuelle Verzeichnis. Viele Dateien gehen in mehreren
    // Anfragen nacheinander hoch (planUploadBatches), damit weder die Grenzen
    // aus php.ini noch die eines vorgeschalteten Webservers (HTTP 413) den
    // ganzen Upload scheitern lassen. Weist der Server eine Anfrage als zu groß
    // ab, wird sie halbiert und erneut gesendet; eine einzelne zu große Datei
    // wird übersprungen und am Ende gemeldet — die übrigen kommen an.
    async upload(fileList, format = '') {
      if (!this.cwd || !fileList?.length) return
      const target = this.cwd.id
      const limits = useAuthStore().uploadLimits ?? { fileBytes: 0, postBytes: 0, maxFiles: 0 }
      const all = Array.from(fileList)
      const rejected = [] // Namen der Dateien, die dem Server zu groß sind
      const queue = []
      for (const f of all) {
        if (limits.fileBytes > 0 && f.size > limits.fileBytes) rejected.push(f.name)
        else queue.push(f)
      }
      const pending = planUploadBatches(queue, limits)
      this.uploading = true
      this.uploadProgress = { done: rejected.length, total: all.length }
      try {
        while (pending.length) {
          const batch = pending.shift()
          const form = new FormData()
          form.append('cmd', 'upload')
          form.append('target', target)
          if (format) form.append('format', format)
          for (const f of batch) form.append('files[]', f)
          try {
            await api.postForm(form)
          } catch (e) {
            const tooLarge = isHttp413(e)
            if (batch.length > 1 && (tooLarge || e instanceof TypeError)) {
              const half = Math.ceil(batch.length / 2)
              pending.unshift(batch.slice(0, half), batch.slice(half))
              continue
            }
            if (!tooLarge) throw e
            rejected.push(batch[0].name)
          }
          this.uploadProgress.done += batch.length
        }
      } finally {
        this.uploading = false
        this.uploadProgress = null
        // Auch nach einem Abbruch neu laden: Ein Teil der Dateien ist dann
        // bereits angekommen.
        if (this.cwd?.id === target) await this.refresh().catch(() => {})
      }
      if (rejected.length) {
        throw new ApiError({ code: 'EINVAL', key: 'UPLOAD-REJECTED-SIZE', params: [rejected.join(', ')] })
      }
    },

    // Download über eine unsichtbare Navigation — der Browser speichert die
    // Antwort dank Content-Disposition: attachment, die App bleibt stehen.
    download(entry) {
      const a = document.createElement('a')
      a.href = api.url('download', { target: entry.id })
      a.download = entry.name
      document.body.appendChild(a)
      a.click()
      a.remove()
    },

    thumbUrl(entry, size = 256) {
      return api.url('thumb', { target: entry.id, size })
    },

    rawUrl(entry) {
      return api.url('raw', { target: entry.id })
    },

    openViewer(entry) {
      this.viewerId = entry.id
    },

    closeViewer() {
      this.viewerId = null
    },

    viewerStep(delta) {
      const list = this.imageEntries
      if (!list.length || this.viewerIndex < 0) return
      const next = (this.viewerIndex + delta + list.length) % list.length
      this.viewerId = list[next].id
    },

    // --- Bild-Editor (filerobot) ----------------------------------------

    openImageEditor(entry) {
      this.editImageId = entry.id
    },

    closeImageEditor() {
      this.editImageId = null
    },

    // Legt neben einem Rasterbild eine Fassung in einem anderen Format an
    // (Server, GD). Das Original bleibt; die neue Datei wird ausgewählt.
    async convertImage(entry, format) {
      const meta = await api.post('convertimage', { target: entry.id, format })
      await this.refresh()
      if (meta?.id) this.selectOnly(meta.id)
      return meta
    },

    // Speichert ein bearbeitetes Rasterbild zurück. dataUrl ist die base64-
    // Ausgabe des Editors (data:image/…;base64,…). mode: 'overwrite' ersetzt
    // die Quelldatei, 'copy' legt unter name eine neue Datei an.
    async saveEditedImage(id, dataUrl, { mode = 'overwrite', name = null } = {}) {
      const body = { target: id, data: dataUrl, mode }
      if (mode === 'copy' && name) body.name = name
      const meta = await api.post('writeimage', body)
      await this.refresh()
      return meta
    },

    // --- Editor (Stufe 1) ------------------------------------------------

    async activate(entry) {
      if (entry.type === 'dir') {
        await this.openDir(entry.id, entry.name)
      } else if (entry.image) {
        this.openViewer(entry)
      } else if (entry.editable) {
        await this.openTextFile(entry)
      }
    },

    async openTextFile(entry) {
      const data = await api.get('read', { target: entry.id })
      this.openFile = { id: entry.id, name: data.name, content: data.content, mtime: data.mtime, path: data.path, contentFile: !!data.contentFile }
      this.dirty = false
    },

    // Lädt die offene Datei neu (z. B. nachdem der KI-Assistent sie geändert
    // hat) und stößt über reloadTick die Anzeige im Editor an. Nur wirksam,
    // wenn sich der Inhalt tatsächlich geändert hat.
    async reloadOpenFile() {
      if (!this.openFile) return
      const data = await api.get('read', { target: this.openFile.id })
      if (data.content === this.openFile.content) return
      this.openFile.content = data.content
      this.openFile.mtime = data.mtime
      this.dirty = false
      this.reloadTick++
    },

    async saveOpenFile(content, { force = false } = {}) {
      if (!this.openFile) return
      // Konfliktschutz: Die mtime des geladenen Standes geht mit; der Server
      // lehnt mit CONFLICT-MTIME ab, wenn die Datei seit dem Öffnen extern
      // geändert wurde. Per force=true wird ohne Prüfung überschrieben.
      const body = { target: this.openFile.id, content }
      if (!force) body.mtime = this.openFile.mtime
      const meta = await api.post('write', body)
      this.openFile.content = content
      this.openFile.mtime = meta.mtime
      this.dirty = false
      const row = this.entries.find((e) => e.id === meta.id)
      if (row) {
        row.size = meta.size
        row.mtime = meta.mtime
      }
    },

    // Legt den aktuellen Editor-Inhalt als Freigabe-Entwurf ab, statt ihn live
    // zu speichern (gestaffelte Veröffentlichung). Die Live-Datei bleibt
    // unverändert; der Entwurf wartet in der Warteschlange auf Freigabe.
    async saveAsDraft(content) {
      if (!this.openFile) return
      await api.post('reviewsave', { target: this.openFile.id, content })
      this.dirty = false
      // Das Abzeichen der Werkzeugschiene zählt die offenen Entwürfe — nach dem
      // Ablegen nachladen, sonst bliebe es bis zum nächsten Öffnen der
      // Warteschlange stehen. Ein Fehler darf das Speichern nicht entwerten.
      useReviewStore().fetch().catch(() => {})
    },

    closeFile() {
      this.openFile = null
      this.dirty = false
    },
  },
})
