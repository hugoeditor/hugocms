<!DOCTYPE html>
<html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo $title ?> - HugoCMS</title>
        <link href="css/bootstrap.min.css" rel="stylesheet">
        <link href="css/bootstrap-markdown-editor.css" rel="stylesheet">
        <link href="css/jquery-ui.min.css" rel="stylesheet">
        <!-- elfinder css -->
        <link rel="stylesheet" href="plugins/elfinder/css/commands.css"    type="text/css">
        <link rel="stylesheet" href="plugins/elfinder/css/common.css"      type="text/css">
        <link rel="stylesheet" href="plugins/elfinder/css/contextmenu.css" type="text/css">
        <link rel="stylesheet" href="plugins/elfinder/css/cwd.css"         type="text/css">
        <link rel="stylesheet" href="plugins/elfinder/css/dialog.css"      type="text/css">
        <link rel="stylesheet" href="plugins/elfinder/css/fonts.css"       type="text/css">
        <link rel="stylesheet" href="plugins/elfinder/css/navbar.css"      type="text/css">
        <link rel="stylesheet" href="plugins/elfinder/css/places.css"      type="text/css">
        <link rel="stylesheet" href="plugins/elfinder/css/quicklook.css"   type="text/css">
        <link rel="stylesheet" href="plugins/elfinder/css/statusbar.css"   type="text/css">
        <link rel="stylesheet" href="plugins/elfinder/css/theme.css"       type="text/css">
        <link rel="stylesheet" href="plugins/elfinder/css/toast.css"       type="text/css">
        <link rel="stylesheet" href="plugins/elfinder/css/toolbar.css"     type="text/css">

        <style>
            #editor-view-front-matter {
                border: 1px solid #ddd;
                border-radius: 4px;
                padding: 0.5em;
                margin-bottom: 1em;
                background-color: #fff;
                border-color: #ddd;
                border-width: 1px;
                border-radius: 4px 4px 0 0;
            }
        </style>
    </head>
    <body>
        <input type="hidden" id="hugocms-client" value="<?php if(isset($_SESSION['hugocms_client'])) echo $_SESSION['hugocms_client']; ?>" />
        <div id="view-header" class="container">
            <div class="row">
                <div class="col-md-6" style="margin-top: 10px;"><?php if(!empty($license_error)) echo '<span class="bg-danger">'.$license_error.'</span>'; else echo '<span class="text-muted" id="license-text"></span> <span class="text-muted">'.$license_user.'</span>'; ?>  <?php if(!$setup_no_cancel) echo '/ <span id="show-mode"></span>'; ?></div>
                <div class="col-md-6 text-right" style="margin-top: 10px;"><span id="lang-en">English</span> / <span id="lang-fr">Français</span> / <span id="lang-de">Deutsch</span></div>
            </div>
        </div>
        <div id="directory-view" class="container" <?php if($setup) echo 'style="display:none;"'; ?>>
            <nav class="navbar navbar-default" style="margin-top: 10px;">
                <div class="container-fluid">
                    <div class="navbar-header">
                        <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#editor-navbar" aria-expanded="false">
                            <span class="sr-only">Toggle navigation</span>
                            <span class="icon-bar"></span>
                            <span class="icon-bar"></span>
                            <span class="icon-bar"></span>
                        </button>
                        <a class="navbar-brand" href="<?php echo SITE_URL; ?>" target="_blank"><?php echo $title ?> - HugoCMS</a>
                    </div>
                    <div class="collapse navbar-collapse" id="editor-navbar">
                    <ul class="nav navbar-nav">
                        <li>
                            <button type="button" id="versioning" class="btn btn-default navbar-btn"></button>
                            <button type="button" id="reset" class="btn btn-default navbar-btn"></button>
                            <button type="button" id="config" class="btn btn-default navbar-btn"></button>
                            <button type="button" id="publish" class="btn btn-default navbar-btn"></button>
                            <button type="button" id="logout" class="btn btn-default navbar-btn" style="margin-left:1em;"></button>
                            <button type="button" id="setup" class="btn btn-default navbar-btn"></button>
                            <button type="button" id="set-mode" class="btn btn-default navbar-btn"></button>
                        </li>
                    </ul>
                    </div>
                </div>
            </nav>
            <div id="elfinder"></div>
        </div>
        <div id="editor-view" class="container" style="display:none">
            <nav class="navbar navbar-default" style="margin-top: 10px;">
                <div class="container-fluid">
                    <div class="navbar-header">
                        <a class="navbar-brand" href="<?php echo SITE_URL; ?>" target="_blank"><?php echo $title ?> - HugoCMS</a>
                    </div>
                </div>
            </nav>
            <div id="editor-area">
            <div style="margin-bottom: 1em">
                <strong><span id="editor-view-filename"></span></strong>
            </div>
                <div id="editor-view-front-matter">
                    <table id="editor-view-var-table" class="table" width="100%">
                        <caption id="editor-view-var-section"></caption>
                        <thead>
                            <tr>
                                <th style="width: 33%;"><span id="editor-view-var-key"></span></th>
                                <th style="width: 60%;"><span id="editor-view-var-value"></span></th>
                                <th style="width: 7%;"></th>
                            </tr>
                        </thead>
                        <tbody>
                        </tbody>
                    </table>
                </div>
                <div id="editor-container">
                    <textarea id="editor"></textarea>
                </div>
                <div id="wysiwyg-editor-container">
                    <textarea id="wysiwyg-editor"></textarea>
                </div>
                <div style="margin-top:10px; margin-bottom:3em;">
                    <button type="button" id="editor-save" class="btn btn-primary save-button"></button>
                    <button type="button" id="editor-save-close" class="btn btn-primary save-button close-editor"></button>
                    <button type="button" id="editor-save-publish" class="btn btn-primary save-button close-editor"></button>
                    <button type="button" id="editor-preview" class="btn btn-primary save-button"></button>
                    <button type="button" id="spellcheck" class="btn btn-success"></button>
                    <button type="button" id="close-no-saving" class="btn btn-default"></button>
                </div>
            </div>
        </div>
        <div id="message-dialog" style="display:none;">
            <div id="message-dialog-error" style="display:none"></div>
            <p id="message-dialog-text"></p>
            <p id="message-dialog-debug" style="display:none"></p>
        </div>
        <div id="spellcheck-dialog" style="display:none;">
            <form>
                <textarea id="spellcheck-textarea" spellcheck="true"></textarea>
            </form>
        </div>
        <div id="commit-msg-dialog" style="display:none;">
            <form>
                <fieldset>
                    <label id="commit-msg-label" for="commit-msg"></label>
                    <input type="text" name="commit-msg" id="commit-msg" class="text ui-widget-content ui-corner-all" placeholder="" />
                </fieldset>
            </form>
        </div>

        <input type="hidden" id="hugocms-initial-setup" value="<?php if($setup_no_cancel) echo 'running'; ?>" />
        <div id="setup-view" class="container" <?php if(!$setup) echo 'style="display:none;"'; ?>>
            <div class="" tabindex="-1" role="dialog">
              <div class="modal-dialog" role="document">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title">HugoCMS <span id="title"></span></h5>
                    <div id="setup-error">
                        <?php echo $error_msg; ?>
                    </div>
                </div>
                <div class="modal-body">
                    <form action="./" method="POST">
                        <div class="form-group">
                            <label id="username-label" for="username"></label>
                            <input type="text" class="form-control" id="username-input" name="user" placeholder="">
                        </div>
                        <div class="form-group">
                            <div class="checkbox" <?php if($setup) echo 'style="display:none;"'; ?>>
                                <label>
                                    <input type="checkbox" id="set-password" name="set-password">
                                    <span id="set-password-label"></span>
                                </label>
                            </div>
                        </div>
                        <div class="form-group password-input">
                            <label id="password-label" for="password"></label>
                            <input type="password" class="form-control" id="password-input" name="password" placeholder="">
                        </div>
                        <div class="form-group password-input">
                            <label id="password-retry-label" for="password"></label>
                            <input type="password" class="form-control" id="password-retry-input" name="password-retry" placeholder="">
                        </div>
                        <div class="form-group">
                            <div class="radio">
                                <label>
                                    <input type="radio" id="easy-mode-input" name="mode" value="easy" checked>
                                    <span id="easy-mode-label"></span>
                                </label>
                            </div>
                            <div class="radio">
                                <label>
                                    <input type="radio"id="normal-mode-input" name="mode" value="normal">
                                    <span id="normal-mode-label"></span>
                                </label>
                            </div>
                            <div class="radio">
                                <label>
                                    <input type="radio"id="admin-mode-input" name="mode" value="admin">
                                    <span id="admin-mode-label"></span>
                                </label>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button id="setup-submit-btn" type="button" class="btn btn-primary"></button>
                            <button id="setup-cancel-btn" type="button" class="btn btn-primary" <?php if($setup_no_cancel) echo 'style="display:none;"'; ?>></button>
                        </div>
                    </form>
                </div>
              </div>
            </div>
        </div>

        <script src="./js/jquery.min.js"></script>
        <script src="./js/bootstrap.min.js"></script>
        <script src="./js/jquery-ui.min.js"></script>
        <script src="./js/src-min-noconflict/ace.js" type="text/javascript" charset="utf-8"></script>
        <script src="./js/bootstrap-markdown-editor.js"></script>
        <script src="./js/marked.min.js"></script>
        <script src="./js/editor.control.js"></script>
        <!-- elfinder core -->
        <script src="plugins/elfinder/js/elFinder.js"></script>
        <script src="plugins/elfinder/js/elFinder.version.js"></script>
        <script src="plugins/elfinder/js/jquery.elfinder.js"></script>
        <script src="plugins/elfinder/js/elFinder.mimetypes.js"></script>
        <script src="plugins/elfinder/js/elFinder.options.js"></script>
        <script src="plugins/elfinder/js/elFinder.options.netmount.js"></script>
        <script src="plugins/elfinder/js/elFinder.history.js"></script>
        <script src="plugins/elfinder/js/elFinder.command.js"></script>
        <script src="plugins/elfinder/js/elFinder.resources.js"></script>
        <!-- elfinder dialog -->
        <script src="plugins/elfinder/js/jquery.dialogelfinder.js"></script>
        <!-- elfinder default lang -->
        <script src="plugins/elfinder/js/i18n/elfinder.en.js"></script>
        <!-- elfinder ui -->
        <script src="plugins/elfinder/js/ui/button.js"></script>
        <script src="plugins/elfinder/js/ui/contextmenu.js"></script>
        <script src="plugins/elfinder/js/ui/cwd.js"></script>
        <script src="plugins/elfinder/js/ui/dialog.js"></script>
        <script src="plugins/elfinder/js/ui/fullscreenbutton.js"></script>
        <script src="plugins/elfinder/js/ui/navbar.js"></script>
        <script src="plugins/elfinder/js/ui/navdock.js"></script>
        <script src="plugins/elfinder/js/ui/overlay.js"></script>
        <script src="plugins/elfinder/js/ui/panel.js"></script>
        <script src="plugins/elfinder/js/ui/path.js"></script>
        <script src="plugins/elfinder/js/ui/places.js"></script>
        <script src="plugins/elfinder/js/ui/searchbutton.js"></script>
        <script src="plugins/elfinder/js/ui/sortbutton.js"></script>
        <script src="plugins/elfinder/js/ui/stat.js"></script>
        <script src="plugins/elfinder/js/ui/toast.js"></script>
        <script src="plugins/elfinder/js/ui/toolbar.js"></script>
        <script src="plugins/elfinder/js/ui/tree.js"></script>
        <script src="plugins/elfinder/js/ui/uploadButton.js"></script>
        <script src="plugins/elfinder/js/ui/viewbutton.js"></script>
        <script src="plugins/elfinder/js/ui/workzone.js"></script>
        <!-- elfinder commands -->
        <script src="plugins/elfinder/js/commands/archive.js"></script>
        <script src="plugins/elfinder/js/commands/back.js"></script>
        <script src="plugins/elfinder/js/commands/chmod.js"></script>
        <script src="plugins/elfinder/js/commands/colwidth.js"></script>
        <script src="plugins/elfinder/js/commands/copy.js"></script>
        <script src="plugins/elfinder/js/commands/cut.js"></script>
        <script src="plugins/elfinder/js/commands/download.js"></script>
        <script src="plugins/elfinder/js/commands/duplicate.js"></script>
        <script src="plugins/elfinder/js/commands/edit.js"></script>
        <script src="plugins/elfinder/js/commands/empty.js"></script>
        <script src="plugins/elfinder/js/commands/extract.js"></script>
        <script src="plugins/elfinder/js/commands/forward.js"></script>
        <script src="plugins/elfinder/js/commands/fullscreen.js"></script>
        <script src="plugins/elfinder/js/commands/getfile.js"></script>
        <script src="plugins/elfinder/js/commands/help.js"></script>
        <script src="plugins/elfinder/js/commands/hidden.js"></script>
        <script src="plugins/elfinder/js/commands/hide.js"></script>
        <script src="plugins/elfinder/js/commands/home.js"></script>
        <script src="plugins/elfinder/js/commands/info.js"></script>
        <script src="plugins/elfinder/js/commands/mkdir.js"></script>
        <script src="plugins/elfinder/js/commands/mkfile.js"></script>
        <script src="plugins/elfinder/js/commands/netmount.js"></script>
        <script src="plugins/elfinder/js/commands/open.js"></script>
        <script src="plugins/elfinder/js/commands/opendir.js"></script>
        <script src="plugins/elfinder/js/commands/opennew.js"></script>
        <script src="plugins/elfinder/js/commands/paste.js"></script>
        <script src="plugins/elfinder/js/commands/places.js"></script>
        <script src="plugins/elfinder/js/commands/preference.js"></script>
        <script src="plugins/elfinder/js/commands/quicklook.js"></script>
        <script src="plugins/elfinder/js/commands/quicklook.plugins.js"></script>
        <script src="plugins/elfinder/js/commands/reload.js"></script>
        <script src="plugins/elfinder/js/commands/rename.js"></script>
        <script src="plugins/elfinder/js/commands/resize.js"></script>
        <script src="plugins/elfinder/js/commands/restore.js"></script>
        <script src="plugins/elfinder/js/commands/rm.js"></script>
        <script src="plugins/elfinder/js/commands/search.js"></script>
        <script src="plugins/elfinder/js/commands/selectall.js"></script>
        <script src="plugins/elfinder/js/commands/selectinvert.js"></script>
        <script src="plugins/elfinder/js/commands/selectnone.js"></script>
        <script src="plugins/elfinder/js/commands/sort.js"></script>
        <script src="plugins/elfinder/js/commands/undo.js"></script>
        <script src="plugins/elfinder/js/commands/up.js"></script>
        <script src="plugins/elfinder/js/commands/upload.js"></script>
        <script src="plugins/elfinder/js/commands/view.js"></script>
        <!-- elfinder languages -->
        <script src="plugins/elfinder/js/i18n/elfinder.de.js"></script>
        <script src="plugins/elfinder/js/i18n/elfinder.fr.js"></script>
        <!-- tinymce wysiwyg editor -->
        <script src="plugins/tinymce/tinymce.min.js" referrerpolicy="origin"></script>

    </body>
</html>
