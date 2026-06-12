// English translations. Placeholders {0}, {1}, … are filled positionally from
// the parameters of the backend messages or the t() arguments.
export default {
  language: {
    de: 'Deutsch',
    en: 'English',
  },

  app: {
    title: 'HugoCMS – File Manager',
    logout: 'Sign out',
    notReady: 'HugoCMS is not ready',
    retry: 'Check again',
    setupWarningTitle: 'Server setup notice',
    close: 'Close',
  },

  login: {
    title: 'Sign in',
    username: 'Username',
    password: 'Password',
    submit: 'Sign in',
  },

  setup: {
    title: 'Set up HugoCMS',
    intro: 'No configuration was found yet. Create the access here — the hugocms.ini will be generated from it.',
    sectionLogin: 'Login',
    sectionDirs: 'Directories and logging',
    username: 'Username',
    password: 'Password',
    passwordHint: 'At least {0} characters',
    passwordConfirm: 'Confirm password',
    sessionPath: 'Session directory',
    sessionPathHint: 'Relative to the backend directory; created if missing.',
    logFile: 'Log file',
    logFileHint: 'Relative to the backend directory; the directory is created.',
    logLevel: 'Log level',
    submit: 'Set up and sign in',
    passwordTooShort: 'The password must be at least {0} characters long.',
    passwordMismatch: 'The passwords do not match.',
  },

  files: {
    places: 'Places',
    chooseMount: 'Select a place on the left.',
    colName: 'Name',
    colSize: 'Size',
    colType: 'Type',
    colModified: 'Modified',
    typeFolder: 'Folder',
    emptyDir: 'This folder is empty',
    noMatch: 'No match for “{0}”',
  },

  nav: {
    back: 'Back',
    forward: 'Forward',
    up: 'Parent folder',
    refresh: 'Refresh',
  },

  view: {
    icons: 'Icon view',
    list: 'List view',
  },

  toolbar: {
    filter: 'Filter in folder …',
  },

  status: {
    items: '{0} items',
    itemOne: '1 item',
    selected: '{0} selected',
  },

  editor: {
    save: 'Save',
    discardConfirm: 'Discard unsaved changes?',
  },

  // Field names — referenced by the backend via {t: "fields.x"} parameters.
  fields: {
    username: 'Username',
    password: 'Password',
    sessionPath: 'Session directory',
    logFile: 'Log file',
    logLevel: 'Log level',
  },

  errors: {
    EUNKNOWN: 'Unknown error.',
    ENETWORK: 'Invalid response from the server (HTTP {0}).',
    EAUTH: 'Authentication required.',
    ENOENT: 'Not found.',
    EACCES: 'Access denied.',
    EINVAL: 'Bad request.',
    EINTERNAL: 'Internal error.',
    EFATAL: 'Internal error.',
    ESESSION: 'Session directory not readable/writable: {0}. Signing in is not possible — please grant read and write permissions to the web server user.',
    ESITE: 'Unknown website “{0}”: neither mounts/{1}.ini nor the fallback mounts.ini is present. Setup pending.',

    'CONFIG-NOT-READABLE': 'Configuration not readable: {0}',
    'CONFIG-INVALID-INI': 'Configuration is not valid INI: {0}',
    'CONFIG-INCOMPLETE': 'Incomplete configuration in {0}: the following required fields are missing or empty: {1}.',

    'AUTH-MISSING': 'Authentication missing: pass the “auth” option or configure it via “config”.',
    'UNKNOWN-COMMAND': 'Unknown command: {0}',
    'LOGIN-FAILED': 'Wrong username or password.',
    'PARAM-MISSING': 'Parameter “{0}” is missing.',
    'OPERATION-NOT-ALLOWED': 'Operation “{0}” is not allowed on this mount.',
    'METHOD-REQUIRED': 'This operation requires {0}.',

    'NOT-A-DIRECTORY': 'Not a directory.',
    'FILE-NOT-FOUND': 'File not found.',
    'FILETYPE-NOT-EDITABLE': 'This file type cannot be opened in the editor.',
    'FILE-TOO-LARGE': 'File is too large for the editor.',
    'FILE-READ-FAILED': 'File could not be read.',
    'FILETYPE-NOT-SAVABLE': 'This file type cannot be saved in the editor.',
    'FILETYPE-NOT-ALLOWED-MOUNT': 'This file type is not allowed on this mount.',
    'CONTENT-TOO-LARGE': 'Content is too large.',
    'TEMPFILE-FAILED': 'Temporary file could not be created.',
    'FILE-SAVE-FAILED': 'File could not be saved.',

    'MOUNT-NAME-INVALID': 'Invalid mount name: {0}',
    'MOUNT-NAME-TAKEN': 'Mount name already in use: {0}',
    'MOUNT-UNKNOWN': 'Unknown mount: {0}',
    'ID-INVALID': 'Invalid ID.',
    'PATH-NOT-FOUND': 'File or directory not found.',
    'TARGET-DIR-NOT-FOUND': 'Target directory not found.',
    'PATH-OUTSIDE-MOUNT': 'Path is outside the mount.',
    'PATH-INVALID-CHAR': 'Invalid character in path.',
    'PARENT-PATH-NOT-ALLOWED': 'Parent paths are not allowed.',
    'MOUNT-PATH-MISSING': 'Mount path does not exist or is not a directory: {0}',

    'MOUNTS-NOT-READABLE': 'Mount configuration not readable: {0}',
    'MOUNTS-INVALID-INI': 'Mount configuration is not valid INI: {0}',
    'MOUNTS-ENTRY-OUTSIDE-SECTION': 'Mount configuration: entry “{0}” is outside a [section].',
    'MOUNTS-PATH-REQUIRED': 'Mount “{0}”: required field “path” is missing.',
    'MOUNTS-NO-SECTION': 'Mount configuration contains no [section]: {0}',

    'AUTH-SINGLEUSER-REQUIRED': '[auth] driver=singleuser: “username” and “password_hash” are required.',
    'AUTH-DRIVER-UNKNOWN': '[auth]: unknown driver “{0}”.',
    'AUTH-DRIVER-INVALID': '[auth]: driver “{0}” did not return an AuthInterface.',

    'SETUP-REQUIRED': 'HugoCMS is not set up yet.',
    'SETUP-METHOD-POST': 'Setup requires POST.',
    'SETUP-ALREADY-CONFIGURED': 'The configuration already exists.',
    'SETUP-DIR-NOT-WRITABLE': 'Directory not writable: {0} — please grant write permissions to the web server user.',
    'SETUP-INI-WRITE-FAILED': 'The hugocms.ini could not be created.',
    'SETUP-AUTOLOGIN-FAILED': 'Automatic sign-in after setup failed.',
    'SETUP-FIELD-REQUIRED': 'Field “{0}” is required.',
    'SETUP-FIELD-INVALID-CHARS': 'Field “{0}” contains invalid characters (quotation marks or line breaks).',
    'SETUP-PASSWORD-TOO-SHORT': 'The password must be at least {0} characters long.',
    'SETUP-LOG-LEVEL-INVALID': 'Invalid log level. Allowed: {0}.',
  },

  warnings: {
    'SESSION-DIR-MISSING': 'Session directory missing: {0} — sign-ins may not persist.',
    'LOG-DIR-MISSING': 'Log directory missing: {0} — messages go to the server log.',
    'MOUNT-CONFIG-MISSING': 'No dedicated mount configuration for “{0}”.',
  },
}
