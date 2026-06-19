// Zeilenweiser Diff (längste gemeinsame Teilfolge) für die Änderungsvorschau
// des KI-Assistenten. Bewusst ohne externe Abhängigkeit — passt zum schlanken
// Projektansatz und genügt für die zeilenbasierte Anzeige im Chat-Panel.
//
// Rückgabe: Liste von { t: 'ctx' | 'add' | 'del', text } oder null, wenn die
// Eingaben für einen Inline-Diff zu groß sind (dann zeigt das Panel die
// einfache Inhalts-Vorschau).

const MAX_PRODUCT = 4_000_000 // m*n-Obergrenze (~16 MB LCS-Tabelle)

export function lineDiff(oldText, newText) {
  const a = String(oldText ?? '').split('\n')
  const b = String(newText ?? '').split('\n')
  const m = a.length
  const n = b.length
  if (m * n > MAX_PRODUCT) return null

  // LCS-Längen rückwärts aufbauen.
  const dp = Array.from({ length: m + 1 }, () => new Uint32Array(n + 1))
  for (let i = m - 1; i >= 0; i--) {
    for (let j = n - 1; j >= 0; j--) {
      dp[i][j] = a[i] === b[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1])
    }
  }

  // Vorwärts zurückverfolgen → Diff-Zeilen.
  const out = []
  let i = 0
  let j = 0
  while (i < m && j < n) {
    if (a[i] === b[j]) {
      out.push({ t: 'ctx', text: a[i] })
      i++
      j++
    } else if (dp[i + 1][j] >= dp[i][j + 1]) {
      out.push({ t: 'del', text: a[i] })
      i++
    } else {
      out.push({ t: 'add', text: b[j] })
      j++
    }
  }
  while (i < m) out.push({ t: 'del', text: a[i++] })
  while (j < n) out.push({ t: 'add', text: b[j++] })

  return out
}
