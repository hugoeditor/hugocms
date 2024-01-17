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

    </head>
    <body>
        <div id="elfinder"></div>


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
        <!-- elfinder initialization  -->
        <script src="./js/editor.elfinder.js"></script>


    </body>
</html>
