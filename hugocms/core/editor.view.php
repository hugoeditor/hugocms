<!DOCTYPE html>
<html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo $title ?> - HugoCMS</title>
        <link href="hugocms/css/bootstrap.min.css" rel="stylesheet">
        <link href="hugocms/css/bootstrap-markdown-editor.css" rel="stylesheet">
        <link href="hugocms/css/jquery-ui.min.css" rel="stylesheet">
        <!-- elfinder css -->
        <link rel="stylesheet" href="hugocms/plugins/elfinder/css/elfinder.min.css" type="text/css">

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

			.editor-btn {
				margin-bottom: 1em;
			}

            /* Dropdown Button */
            .dropbtn {
                background-color: white;
                color: black;
                padding: 6px;
                /*font-size: 12px;*/
                border: solid 1px;
                border-radius: 4px;
                border-color: #ccc;
                cursor: pointer;
            }

            /* Dropdown button on hover & focus */
            .dropbtn:hover, .dropbtn:focus {
                background-color: #286090;
                color: #fff;
            }

            /* The container <div> - needed to position the dropdown content */
            .dropdown {
                position: relative;
                display: inline-block;
            }

            /* Dropdown Content (Hidden by Default) */
            .dropdown-content {
                display: none;
                position: absolute;
                background-color: white;
                min-width: 160px;
                box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.4);
                z-index: 1;
            }

            /* Links inside the dropdown */
            .dropdown-content a {
                color: black;
                padding: 12px 16px;
                text-decoration: none;
                display: block;
            }

            /* Change color of dropdown links on hover */
            .dropdown-content a:hover {
                background-color: #ddd;
            }

            /* Show the dropdown menu (use JS to add this class to the .dropdown-content container when the user clicks on the dropdown button) */
            .show {
                display:block;
            }

            .overlay {
                position: absolute;
                background: rgba(0, 0, 0, .5);
                z-index: 99999999;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
            }

            .overlay-info {
                padding: 1em;
                background: white;
                font-size: 1.1em;
                text-align: center;
            }
        </style>
    </head>
    <body>
        <input type="hidden" id="hugocms-client" value="<?php if(isset($_SESSION['hugocms_client'])) echo $_SESSION['hugocms_client']; ?>" />
        <div id="view-header" class="container">
            <div class="row">
                <div class="col-md-6" style="margin-top: 10px;"><?php if(!empty($license_error)) echo '<span class="bg-danger">'.$license_error.'</span>'; else echo '<span class="text-muted" id="license-text"></span> <span class="text-muted">'.$license_user.'</span>'; ?>  <?php if(!$setup_no_cancel) echo '/ <span id="show-mode"></span>'; ?></div>
                <div class="col-md-6 text-right" style="margin-top: 10px;">
                    <div class="dropdown text-left">
                        <button onclick="toggleLanguageMenu()" class="dropbtn">Language</button>
                        <div id="language-menu" class="dropdown-content">
                            <a id="lang-en" href="#">English</a>
                            <a id="lang-fr" href="#">Français</a>
                            <a id="lang-de" href="#">Deutsch</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="overlay" <?php if(!$setup_ready) echo 'style="display:none;"'; ?>>
            <div class="overlay-info">
                <div id="setup-ready" style="padding:.5em; display:inline-block"></div>
                <button id="setup-ready-button" onclick="reloadAfterSetup()" class="btn btn-primary">Reload</button>
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
                    <ul class="nav navbar-nav" style="display:none;">
                        <li>
                            <button type="button" id="versioning" class="btn btn-default navbar-btn"></button>
                            <button type="button" id="reset" class="btn btn-default navbar-btn"></button>
                            <button type="button" id="config" class="btn btn-default navbar-btn"></button>
                            <button type="button" id="publish" class="btn btn-default navbar-btn"></button>
                            <button type="button" id="setup" class="btn btn-default navbar-btn"></button>
                            <button type="button" id="new-project" class="btn btn-default navbar-btn"></button>
                            <div class="dropdown text-left">
                                <button id="set-mode-button" onclick="toggleModeMenu()" class="dropbtn"></button>
                                <div id="set-mode-menu" class="dropdown-content">
                                    <a id="set-easy-mode" href="#"></a>
                                    <a id="set-normal-mode" href="#"></a>
                                    <a id="set-admin-mode" href="#"></a>
                                </div>
                            </div>
                            <button type="button" id="logout" class="btn btn-default navbar-btn" style="margin-left:1em;"></button>
                        </li>
                    </ul>
                    </div>
                </div>
            </nav>
            <div id="elfinder-wrapper">
                <div id="elfinder"></div>
            </div>
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
					<div class="row" style="margin-bottom: 1em">
						<div class="col-md-8"><strong><span id="editor-view-filename"></span></strong></div>
						<div class="col-md-4" style="text-align: right"><button id="toggle-front-matter" class="btn btn-info"></button></div>
					</div>
				</div>
                <div id="editor-view-front-matter" style="display:none;">
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
                    <button type="button" id="editor-save" class="btn btn-primary save-button editor-btn"></button>
                    <button type="button" id="editor-save-close" class="btn btn-primary save-button close-editor editor-btn"></button>
                    <button type="button" id="editor-save-publish" class="btn btn-primary save-button close-editor editor-btn"></button>
                    <button type="button" id="editor-preview" class="btn btn-primary save-button editor-btn"></button>
                    <button type="button" id="spellcheck" class="btn btn-success editor-btn"></button>
                    <button type="button" id="close-no-saving" class="btn btn-default editor-btn"></button>
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
        <div id="new-project-dialog" style="display:none;">
            <form>
                <fieldset>
                    <label id="new-project-label" for="new-project-input"></label>
                    <input type="text" name="new-project-input" id="new-project-input" class="text ui-widget-content ui-corner-all" placeholder="" />
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
                            <input type="text" class="form-control" id="username-input" name="user" placeholder="" autofocus>
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
                            <label id="licensee-label" for="licensee-input"></label>
                            <input type="text" class="form-control" id="licensee-input" name="licensee-input" placeholder="" value="<?php if($setup) echo $licensee; ?>">
                        </div>
                        <div class="form-group">
                            <label id="license-key-label" for="license-key-input"></label>
                            <input type="text" class="form-control" id="license-key-input" name="license-key-input" placeholder="" value="<?php if($setup) echo $licenseKey; ?>">
                        </div>
                        <div class="form-group create-project-input" <?php if(!$setup) echo 'style="display:none;"'; ?>>
                            <label id="create-project-label" for="create-project-input"></label>
                            <input type="text" class="form-control" id="create-project-input" name="create-project-input" placeholder="">
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
                        <div class="form-group">
                            <div class="checkbox">
                                <label>
                                    <input type="checkbox" id="use-purgecss" name="use-purgecss" <?php if(!empty($license_error)) echo 'disabled' ?>>
                                    <span id="use-purgecss-label"></span>
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

        <script src="./hugocms/js/jquery.min.js"></script>
        <script src="./hugocms/js/bootstrap.min.js"></script>
        <script src="./hugocms/js/jquery-ui.min.js"></script>
        <script src="./hugocms/js/src-min-noconflict/ace.js" type="text/javascript" charset="utf-8"></script>
        <script src="./hugocms/js/bootstrap-markdown-editor.js"></script>
        <script src="./hugocms/js/marked.min.js"></script>
        <script src="./hugocms/js/editor.control.js"></script>
        <!-- elfinder core -->
        <script src="hugocms/plugins/elfinder/js/elfinder.min.js"></script>
        <!-- elfinder languages -->
        <script src="hugocms/plugins/elfinder/js/i18n/elfinder.de.js"></script>
        <script src="hugocms/plugins/elfinder/js/i18n/elfinder.fr.js"></script>
        <!-- tinymce wysiwyg editor -->
        <script src="hugocms/plugins/tinymce/tinymce.min.js" referrerpolicy="origin"></script>

    </body>
</html>
