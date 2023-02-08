<!DOCTYPE html>
<html lang="de">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<title><?php echo $title ?> - HugoCMS</title>
		<link href="./css/bootstrap.min.css" rel="stylesheet">
		<link href="./css/bootstrap-markdown-editor.css" rel="stylesheet">
		<link href="./css/jquery-ui.min.css" rel="stylesheet">
	</head>
	<body>
		<div class="container">
			<div class="row">
				<div class="col-md-4" style="margin-top: 10px;"><?php if(!empty($license_error)) echo '<span class="bg-danger">'.$license_error.'</span>'; else echo '<span class="text-muted" id="license-text"></span> <span class="text-muted">'.$license_user.'</span>'; ?></div>
				<div class="col-md-8 text-right" style="margin-top: 10px;"><span id="lang-en">En</span> / <span id="lang-de">De<span></div>
			</div>
		</div>
		<div id="directory-view" class="container">
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
							<button type="button" id="mkdir" class="btn btn-default navbar-btn"></button>
							<button type="button" id="new-file" class="btn btn-default navbar-btn"></button>
						</li>
					</ul>
					</div>
				</div>
			</nav>
			<p id="navi"></p>
			<table class="table">
			<thead>
				<tr>
					<th id="th-filename"></th>
					<th id="th-filetype"></th>
					<th id="th-access"></th>
					<th id="th-action"></th>
				</tr>
			</thead>
			<tbody id="directory-list">
			</tbody>
			</table>
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
			<div class="row" style="margin-bottom: 1em">
				<div class="col-md-8"><strong><span id="editor-view-filename"></span></strong></div>
				<div class="col-md-4" style="text-align: right"><a id="close-no-saving" class="text-muted close-editor" href="#"></a></div>
			</div>
			<form>
				<textarea id="editor"></textarea>
				<div style="margin-top:10px;">
					<button type="button" id="editor-save" class="btn btn-primary save-button"></button>
					<button type="button" id="editor-save-close" class="btn btn-primary save-button close-editor"></button>
					<button type="button" id="editor-preview" class="btn btn-primary save-button"></button>
					<button type="button" id="spellcheck" class="btn btn-success"></button>
				</div>
			</form>
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
		<div id="mkdir-dialog" style="display:none;">
			<form>
				<fieldset>
					<label id="dirname-label" for="dirname"></label>
					<input type="text" name="dirname" id="dirname" class="text ui-widget-content ui-corner-all" placeholder="" />
				</fieldset>
			</form>
		</div>
		<div id="new-file-dialog" style="display:none;">
			<form>
				<fieldset>
					<label id="filename-label" for="filename"></label>
					<input type="text" name="filename" id="filename" class="text ui-widget-content ui-corner-all" placeholder="" />
				</fieldset>
			</form>
		</div>
		<div id="confirm-del-dialog" style="display:none;">
			<div id="confirm-del-file"></div>
		</div>
		<div id="rename-dialog" style="display:none;">
			<p id="rename-dialog-target"></p>
			<form>
				<fieldset>
					<label id="new-filename-label" for="rename-dialog-filename"></label>
					<input type="text" name="rename-dialog-filename" id="rename-dialog-filename" class="text ui-widget-content ui-corner-all" placeholder="" />
				</fieldset>
			</form>
		</div>
		<div id="config-dialog" style="display:none;">
			<form>
				<textarea id="config-textarea" spellcheck="false" rows="42" cols="80"></textarea>
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

		<script src="./js/jquery.min.js"></script>
		<script src="./js/bootstrap.min.js"></script>
		<script src="./js/jquery-ui.min.js"></script>
		<script src="./js/src-min-noconflict/ace.js" type="text/javascript" charset="utf-8"></script>
		<script src="./js/bootstrap-markdown-editor.js"></script>
		<script src="./js/marked.min.js"></script>
		<script src="./js/editor.control.js"></script>
	</body>
</html>
