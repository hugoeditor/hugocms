function exists(obj)
{
    return obj !== null && obj !== undefined && typeof obj != "undefined";
}

function isEmpty(obj)
{
    return isIterable( obj ) && obj.length === 0;
}

function removeFrontMatterVariables(e)
{
    $(e).closest('tr').remove();
    return false;
}

function toggleLanguageMenu()
{
    document.getElementById("language-menu").classList.toggle("show");
}

function toggleModeMenu()
{
    document.getElementById("set-mode-menu").classList.toggle("show");
}

function reloadAfterSetup()
{
    window.open('./', '_self');
}

$(document).ready(function()
{
    var cmsMode = 'easy';
    var cmsUsePurgeCSS = false;
    var cmsLang = 'en';
    var cmsUser = '';
    var cmsFrontMatterTemplate = {};
    var cmsMarkdownEditor = true;
	var cmsHideFrontMatter = true;

    // Close the dropdown menu if the user clicks outside of it
    window.onclick = function(event)
    {
        if (!event.target.matches('.dropbtn'))
        {
            var dropdowns = document.getElementsByClassName("dropdown-content");
            var i;
            for (i = 0; i < dropdowns.length; i++)
            {
                var openDropdown = dropdowns[i];
                if (openDropdown.classList.contains('show'))
                {
                    openDropdown.classList.remove('show');
                }
            }
        }
    }

    function initTinyMCE()
    {
        let tinymceLang = cmsLang;
        if('fr' == tinymceLang) tinymceLang = 'fr_FR';
        tinymce.init({
            selector: '#wysiwyg-editor',
            language: tinymceLang,
            plugins: 'preview importcss searchreplace autolink autosave save directionality code visualblocks visualchars fullscreen image link media codesample table charmap pagebreak nonbreaking anchor insertdatetime advlist lists wordcount help charmap quickbars emoticons',
            menubar: 'file edit view insert format tools table help',
            toolbar: 'undo redo | bold italic underline strikethrough | fontselect fontsizeselect formatselect | alignleft aligncenter alignright alignjustify | outdent indent |  numlist bullist | forecolor backcolor removeformat | pagebreak | charmap emoticons | fullscreen  preview save print | insertfile image media template link anchor codesample | ltr rtl',
            toolbar_sticky: true,
        });
    }

    $.extend($.ui.dialog.prototype.options,
    {
        create: function()
        {
            $(this).keypress(function(e)
            {
                if( e.keyCode == $.ui.keyCode.ENTER )
                {
                    $(this).parent().find('.ui-dialog-buttonpane button:first').click();
                    return false;
                }
            });
        }
    });

    $('#message-dialog').dialog(
    {
        autoOpen: false,
        resizable: true,
        width: '1024',
        height: 'auto',
        modal: true,
        position: { my: "left top", at: "left bottom", of: 'nav' },
        open: function()
        {
            $(this).css('maxWidth', window.innerWidth);
        },
        buttons:
        [{
            text: 'Ok',
            click: function()
            {
                $('#message-dialog-text').html('');
                $('#message-dialog-debug').html('');
                $('#message-dialog-error').hide();
                $(this).parent().removeClass('ui-state-error');
                $(this).parent().removeClass('ui-state-success');
                $(this).dialog("close");
            }
        }]
    });

    $.fn.showMessageDialog = function(style, title, message, debug=null)
    {
        $(this).dialog('option', 'title', title).dialog('open').parent().addClass('ui-state-' + style);
        if('error' === style) $('#message-dialog-error').css('display', '');
        $('#message-dialog-text').html(message);
        if(null != debug) $('#message-dialog-debug').html('<p>' + debug + '</p>').css('display', '');
        $(this).resize();
    }

    $('#commit-msg-dialog').dialog(
    {
        autoOpen: false,
        resizable: false,
        width: 'auto',
        height: 'auto',
        modal: true,
        title: 'Versioning',
        position: { my: "left top", at: "left bottom", of: '#versioning' },
        open: function()
        {
            const date = new Date();
            $('#commit-msg').val(translate('Changed on') + ' ' + date.toLocaleString());
            $('#commit-msg').click();
        },
        buttons:
        [{
            text: 'Ok',
            click: function()
            {
                $('#commit-msg-dialog-error').remove();
                let commit_msg = $('#commit-msg').val();
                if(commit_msg)
                {
                    $.ajax({
                        url: 'index.php',
                        type: 'POST',
                        data: { action: 'editor\\versioning', data: { 'commsg': commit_msg }, client: $('#hugocms-client').val() },
                        success: function(data)
                        {
                            if(data.hasOwnProperty('session_expired'))
                            {
                                alert(translate('Session is expired!'));
                                window.open('./', '_self');
                                return;
                            }
                            let message = (data.success)? translate('The changes have been entered into the version management.') : translate('The changes could not be entered into the version management!');
                            $('#message-dialog').showMessageDialog( (data.success)? 'success' : 'error', translate('Versioning'), message, (data.hasOwnProperty('debug'))? data.debug : null);
                        },
                        error: function()
                        {
                            console.log('versioning ajax call error');
                            $('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate('The server could not process the request!'));
                        }
                    });
                    $(this).dialog("close");
                    return false;
                }

                $('#commit-msg-dialog').prepend('<p id="commit-msg-dialog-error">' + translate('Please enter a commit message!') + '</p>');
            }
        },
        {
            text: 'Abbrechen',
            class: 'dlg-btn-cancel',
            click: function()
            {
                $('#commit-msg-dialog-error').remove();
                $(this).dialog("close");
                return false;
            }
        }]
    });

    $('#new-project-dialog').dialog(
    {
        autoOpen: false,
        resizable: false,
        width: 'auto',
        height: 'auto',
        modal: true,
        title: 'Versioning',
        position: { my: "left top", at: "left bottom", of: '#new-project' },
        open: function()
        {
            $('#new-project-input').click();
        },
        buttons:
        [{
            text: 'Ok',
            click: function()
            {
                $('#new-project-dialog-error').remove();
                let project_name = $('#new-project-input').val();
                if(project_name)
                {
                    $.ajax({
                        url: 'index.php',
                        type: 'POST',
                        data: { action: 'editor\\newProject', data: { 'project_name': project_name, 'username': cmsUser }, client: $('#hugocms-client').val() },
                        success: function(data)
                        {
                            if(data.hasOwnProperty('session_expired'))
                            {
                                alert(translate('Session is expired!'));
                                window.open('./', '_self');
                                return;
                            }
                            let message = (data.success)? translate('A new project for a different domain are created.') : translate('The new project cannot created!');
                            $('#message-dialog').showMessageDialog( (data.success)? 'success' : 'error', translate('Versioning'), message, (data.hasOwnProperty('debug'))? data.debug : null);
                        },
                        error: function()
                        {
                            console.log('versioning ajax call error');
                            $('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate('The server could not process the request!'));
                        }
                    });
                    $(this).dialog("close");
                    return false;
                }

                $('#new-project-dialog').prepend('<p class="text-danger" id="new-project-dialog-error">' + translate('Please enter a project name!') + '</p>');
            }
        },
        {
            text: 'Abbrechen',
            class: 'dlg-btn-cancel',
            click: function()
            {
                $('#new-project-dialog-error').remove();
                $(this).dialog("close");
                return false;
            }
        }]
    });
    
    $('#versioning').click(function()
    {
        $('#commit-msg-dialog').dialog('open');
        return false;
    });

    $('#reset').click(function()
    {
        if(confirm(translate("Revert to the latest restore point. Continue?")))
        {
            $.ajax({
                url: 'index.php',
                type: 'POST',
                data: { action: 'editor\\restore', client: $('#hugocms-client').val() },
                success: function(data)
                {
                    if(data.hasOwnProperty('session_expired'))
                    {
                        alert(translate('Session is expired!'));
                        window.open('./', '_self');
                        return;
                    }
                    let message = (data.success)? translate('The changes have been rolled back.') : translate('The changes could not be rolled back!');
                    $('#message-dialog').showMessageDialog( (data.success)? 'success' : 'error', translate('Restore'), message, (data.hasOwnProperty('debug'))? data.debug : null);
                    $('#navi, #directory-list').refreshDirectoryView();
                },
                error: function()
                {
                    console.log('reset ajax call error');
                    $('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate('The server could not process the request!'));
                }
            });
        }
        return false;
    });

    function textareaSpecialKeys(e)
    {
        var keycode = (e.keyCode ? e.keyCode : e.which);
        if(keycode == 13 || keycode == 9)
        {
            e.preventDefault();
            let pos = this.selectionStart;
            let end = this.selectionEnd;
            this.value = this.value.substring(0, this.selectionStart) + ((keycode == 13)? "\n" : "\t") + this.value.substring(this.selectionEnd);
            this.selectionStart = pos + 1;
            this.selectionEnd = end + 1;
            return false;
        }
    }

    $('#config').click(function()
    {
        const file = { path: (('admin' == cmsMode)? '../config.json' : 'config.json') };
        openEditor(file, true, false);
        return false;
    });

    $('#editor-save-publish').click(function()
    {
        saveFile(function()
        {
            $('#publish').click();
        });
    });

    $('#publish').click(function()
    {
        $.ajax({
            url: 'index.php',
            type: 'POST',
            data: { action: 'editor\\publish', client: $('#hugocms-client').val() },
            success: function(data)
            {
                if(data.hasOwnProperty('session_expired'))
                {
                    alert(translate('Session is expired!'));
                    window.open('./', '_self');
                    return;
                }
                if(!cmsUsePurgeCSS && data.success)
                {
                    let message = (data.success)? translate('The website has been published.') : translate('The website could not be published!');
                    $('#message-dialog').showMessageDialog( (data.success)? 'success' : 'error', translate('Publish'), message, (data.hasOwnProperty('debug'))? data.debug : null);
                    setTimeout(function()
                    {
                        $('#message-dialog').dialog('close');
                    }, 2000);
                }
                if(!data.success)
                {
                    $('#message-dialog').showMessageDialog('error', translate('Publish'), translate('The website could not be published!'), (data.hasOwnProperty('debug'))? data.debug : null);
                    return;
                }

                if(cmsUsePurgeCSS)
                {
                    $.ajax({
                        url: 'index.php',
                        type: 'POST',
                        data: { action: 'editor\\purgecss', client: $('#hugocms-client').val() },
                        success: function(data)
                        {
                            if(data.hasOwnProperty('session_expired'))
                            {
                                alert(translate('Session is expired!'));
                                window.open('./', '_self');
                                return;
                            }
                            let message = (data.success)? translate('The website has been published an the CSS files have been minimized.') : translate('The CSS files could not be minimized!');
                            $('#message-dialog').showMessageDialog( (data.success)? 'success' : 'error', 'PurgeCSS', message, (data.hasOwnProperty('debug'))? data.debug : null);
                            if(data.success)
                            {
                                setTimeout(function()
                                {
                                   $('#message-dialog').dialog('close');
                                }, 2000);
                            }
                        },
                        error: function()
                        {
                            console.log('purgecss ajax call error');
                            $('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate('The server could not process the request!'));
                        }
                    });
                }
            },
            error: function()
            {
                console.log('publish ajax call error');
                $('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate('The server could not process the request!'));
         }
        });
        return false;
    });

	$('#toggle-front-matter').click(function()
	{
		if($('#editor-view-front-matter').is(':visible'))
		{
	        $('#toggle-front-matter').text(translate('Show Front matter variables'));
			$('#editor-view-front-matter').hide();
			cmsHideFrontMatter = true;
		}
		else
		{
	        $('#toggle-front-matter').text(translate('Hide Front matter variables'));
			$('#editor-view-front-matter').show();
			cmsHideFrontMatter = false;
		}
	});

    function parseFrontMatterVariables(data)
    {
        // HugoCMS Front matter variablen auslesen, in einem assoziativen Array speichern und aus dem Text entfernen
        let regex = /---(.*?)---/s;
        let content = regex.exec(data);
        if(content)
        {
            let frontmater_section = content[1];
            let frontmater_lines = frontmater_section.split('\n');
            let frontmater = {};
            for(let i = 0; i < frontmater_lines.length; i++)
            {
                let frontmater_line = frontmater_lines[i];
                let frontmater_key_value = frontmater_line.split(':');
                if(frontmater_key_value.length >= 2)
                {
                    const key = frontmater_key_value[0].trim();
                    let value = frontmater_key_value[1].trim();
					if(frontmater_key_value.length > 2)
					{
						for(let s = 2; s < frontmater_key_value.length; s++) value += (':' + frontmater_key_value[s]).trim();
					}
                    if(value.startsWith('[') && value.endsWith(']'))
                    {
                        const value_array = value.split(',');
                        for(let s = 0; s < value_array.length; s++)
                        {
                            //Gänsefüßchen durch den HTML 'special char code' ersetzen
                            value_array[s] = value_array[s].replace(/"/g, '&quot;');
                        }
                        frontmater[key] = value_array;
                    }
                    else
                    {
                        frontmater[key] = value.trim().replace(/"/g, '');
                    }
                }
            }
            return frontmater;
        }
        return false;
    }

    //Die eigentliche Funktion um den Editor zu öffnen (Double-Click auf Datei im elFinder Datei-Browser)
    const getFileCallback = function(file)
    {
        if(file.path.endsWith('.html')) openEditor(file, false);
        else if(file.path.endsWith('.md')) openEditor(file, true);
        else openEditor(file, true, false, false);
    }

    const openEditor = function(file, markdownEditor = true, editFrontMatter = true, editContent = true)
	{
        $.ajax(
        {
            url: 'index.php',
            type: 'POST',
            data: { action: 'load', file: file.path, mode: cmsMode, client: $('#hugocms-client').val() },
            success: function(data)
            {
                if(exists(data.success) && !data.success)
                {
                    if(data.hasOwnProperty('session_expired') || data.hasOwnProperty('invalid_session'))
                    {
                        alert(translate('Session is expired!'));
                        window.open('./', '_self');
                        return;
                    }
                    $('#message-dialog').showMessageDialog('error', translate('Open file'), translate('The file could not be edit!'), (data.hasOwnProperty('debug'))? data.debug : null);
                    return;
                }
                currentFile.set(file.path);
                $('#directory-view').hide();
                $('#editor-view').show();
                $('#editor-view-filename').text(currentFile.get());

                $('#editor-view-var-table > tbody').html('');
                if(editFrontMatter)
                {
                    $('.md-toolbar').show();
                    if(!cmsHideFrontMatter) $('#editor-view-front-matter').show();
                    $('#toggle-front-matter').show();
                    $('#editor-preview').show();

                    const frontmater = parseFrontMatterVariables(data);
                    if(false !== frontmater)
                    {
                        $.each(frontmater, function(frontmater_key, frontmater_value)
                        {
                            $('#editor-view-var-table > tbody').append('<tr><td><input type="text" class="front-matter-key" style="width: 100%" value="' + frontmater_key + '"></input></td><td><input type="text" class="front-matter-value" style="width: 100%" value="' + frontmater_value + '"></input></td><td><button class="remove-front-matter-var-btn" onclick="removeFrontMatterVariables(this);">' + translate('Remove') + '</button></td></tr>');
                        });
                        data = data.replace(/---([\s\S]*?)---\n/s, '');
                    }
                    $('#editor-view-var-table > tbody').append('<tr><td colspan="2"><select id="frontmatter-keys" style="min-height: 1.8em; min-width:15em;"><option value=""></option></select><button id="add-front-matter-var" style="margin-left: 1em;">' + translate('Add variable') + '</button><button id="use-front-matter-temp" style="margin-left: 1em;">' + translate('Use template') + '</button></td><td></td></tr>');
                    $.each(cmsFrontMatterTemplate, function(frontmater_key, frontmater_value)
                    {
                        $('#frontmatter-keys').append('<option value="' + frontmater_key + '">' + frontmater_key + '</option>');
                    });
                    $('#add-front-matter-var').click(function()
                    {
                        const row = '<tr><td><input type="text" class="front-matter-key" style="width: 100%" value=""></input></td><td><input type="text" class="front-matter-value" style="width: 100%" value=""></input></td><td><button onclick="removeFrontMatterVariables(this);">' + translate('Remove') + '</button></td></tr>';
                        if($(this).closest('table').find('tr:last').prev().length === 0) $(this).closest('table').find('tr:last').before(row);
                        else $(this).closest('table').find('tr:last').prev().after(row);
                        return false;
                    });
                    $('#frontmatter-keys').change(function()
                    {
                        const key = $(this).val();
                        if(key)
                        {
                            let value = cmsFrontMatterTemplate[key];
                            if(!exists(value)) value = '';
                            const row = '<tr><td><input type="text" class="front-matter-key" style="width: 100%" value="' + key + '"></input></td><td><input type="text" class="front-matter-value" style="width: 100%" value="' + value + '"></input></td><td><button onclick="removeFrontMatterVariables(this);">' + translate('Remove') + '</button></td></tr>';
                            console.log($(this).closest('table'));
                            if($(this).closest('table').find('tr:last').prev().length === 0) $(this).closest('table').find('tr:last').before(row);
                            else $(this).closest('table').find('tr:last').prev().after(row);
                        }
                    });
                    $('#use-front-matter-temp').click(function()
                    {
                        let usedKeys = [];
                        $('.front-matter-key').each(function()
                        {
                            usedKeys.push($(this).val());
                        });
                        for (const [key, value] of Object.entries(cmsFrontMatterTemplate))
                        {
                            if(usedKeys.includes(key)) continue;
                            const row = '<tr><td><input type="text" class="front-matter-key" style="width: 100%" value="' + key + '"></input></td><td><input type="text" class="front-matter-value" style="width: 100%" value="' + value + '"></input></td><td><button onclick="removeFrontMatterVariables(this);">' + translate('Remove') + '</button></td></tr>';
                            if($(this).closest('table').find('tr:last').prev().length === 0) $(this).closest('table').find('tr:last').before(row);
                            else $(this).closest('table').find('tr:last').prev().after(row);
                        }
                        return false;
                    });
                }
                else
                {
                    $('.md-toolbar').hide();
                    $('#editor-view-front-matter').hide();
                    $('#toggle-front-matter').hide();
                    $('editor-preview').hide();
                }

                if(markdownEditor)
                {
                    $('#wysiwyg-editor-container').hide();
                    $('#editor-container').show();
                    cmsMarkdownEditor = true;
                    ace.edit($('.md-editor')[0]).setValue(data, 1);
                }
                else
                {
                    $('#wysiwyg-editor-container').show();
                    $('#editor-container').hide();
                    cmsMarkdownEditor = false;
                    tinymce.get("wysiwyg-editor").setContent(data);
                }

                if(editContent) $('#editor-preview').show();
                else $('#editor-preview').hide();
            },
            error: function()
            {
                console.log('loadfile ajax call error');
            }
        });
    };

    $.fn.openFileBrowser = function()
    {
        $(this).elfinder(
            // 1st Arg - options
            {
                lang: cmsLang,

                rememberLastDir: false,
                useBrowserHistory: false,

                // Disable CSS auto loading
                cssAutoLoad : false,

                // Base URL to css/*, js/*
                baseUrl : './',

                // Connector URL
                url : 'index.php' + '?action=elfinder&mode=' + cmsMode + '&client=' + $('#hugocms-client').val(),

                commands : [
                    'cms_edit_md', 'cms_edit_html', 'reload', 'home', 'up', 'back', 'forward', 'getfile', 'quicklook',
                    'download', 'rm', 'duplicate', 'rename', 'mkdir', 'mkfile', 'upload', 'copy',
                    'cut', 'paste', 'edit', 'extract', 'archive', 'search', 'info', 'view', 'help', 'resize', 'sort', 'netmount'
                ],
                contextmenu : {
                    // navbarfolder menu
                    navbar : ['edit', '|', 'copy', 'cut', 'paste', 'duplicate', '|', 'rm', '|', 'info'],
                    // current directory menu
                    cwd    : ['reload', 'back', '|', 'upload', 'mkdir', 'mkfile', 'paste', '|', 'sort', '|', 'info'],
                    // current directory file menu
                    files  : ['getfile', '|', 'cms_edit_md', 'cms_edit_html', 'quicklook', '|', 'download', '|', 'copy', 'cut', 'paste', 'duplicate', '|', 'rm', '|', 'rename', 'resize', '|', 'archive', 'extract', '|', 'info']
                },

                // Callback when a file is double-clicked
                getFileCallback : getFileCallback,

                handlers : {
                    error : function(error)
					{
                        if('errLogout' == error.data.error)
                        {
                            alert(translate('Session is expired!'));
                            window.open('./', '_self');
                            data.result = false;
                        }
                    }
               }
            },

            elFinder.prototype.i18.en.messages.errLogout = "Session is expired!",

            // 2nd Arg - before boot up function
            function(fm, extraObj)
            {
                // `init` event callback function
                fm.bind('init', function()
                {
                    // Optional for Japanese decoder "extras/encoding-japanese.min"
                    delete fm.options.rawStringDecoder;
                    if (fm.lang === 'ja')
                    {
                        fm.loadScript(
                            [ fm.baseUrl + 'js/extras/encoding-japanese.min.js' ],
                            function()
                            {
                                if (window.Encoding && Encoding.convert)
                                {
                                    fm.options.rawStringDecoder = function(s)
                                    {
                                        return Encoding.convert(s,{to:'UNICODE',type:'string'});
                                    };
                                }
                            },
                            { loadType: 'tag' }
                        );
                    }
                });

                // Optional for set document.title dynamically.
                var title = document.title;
                fm.bind('open', function()
                {
                    var path = '',
                        cwd  = fm.cwd();
                    if (cwd)
                    {
                        path = fm.path(cwd.hash) || null;
                    }
                    document.title = path? path + ' - ' + title : title;
                }).bind('destroy', function()
                {
                    document.title = title;
                });
            }
        );
    }

    elFinder.prototype.commands.cms_edit_md = function()
	{
        this.exec = function(hashes)
		{
            //implement what the command should do here
            const file = { path: this.fm.path(hashes[0]) };
            openEditor(file);
        }
        this.getstate = function(hashes)
		{
            //return 0 to enable, -1 to disable icon access
			if(undefined != hashes)
			{
				const file = this.fm.file(hashes[0]);
				return ('directory' !== file.mime)? 0 : -1;
			}
            return -1;
        }
    }

    elFinder.prototype.commands.cms_edit_html = function()
	{
		this.exec = function(hashes)
		{
            //implement what the command should do here
			const file = { path: this.fm.path(hashes[0]) };
            openEditor(file, false);
        }
        this.getstate = function(hashes)
		{
            //return 0 to enable, -1 to disable icon access
			if(undefined != hashes)
			{
				const file = this.fm.file(hashes[0]);
				return ('directory' !== file.mime)? 0 : -1;
			}
            return -1;
        }
    }

    var currentFile = {
        filename: '',
        set: function(file)
        {
            this.filename = file;
        },
        get: function()
        {
            return this.filename;
        },
        getContentFile: function()
        {
            let tmp = this.filename.split('/');
            if(tmp.length == 0) return this.filename;
            let path = '';
            for(let i = (('admin' == cmsMode)? 2 : 1); i < tmp.length - 1; i++) path += tmp[i] + '/';
            return path + tmp[tmp.length - 1].split('.')[0].toLowerCase();
        }
    };

    $('.save-button').click(function()
    {
        saveFile();
    });

    function saveFile(fx = null)
    {
        let frontmatter = '';
        $('#editor-view-var-table > tbody > tr').each(function()
        {
            let key = $(this).find('.front-matter-key').val();
            let value = $(this).find('.front-matter-value').val();
            if(exists(key) && exists(value))
            {
                key = key.trim();
                value = value.trim();
                if('' != key)
                {
                    if(value.startsWith('[') && value.endsWith(']') || 'false' == value || 'true' == value) frontmatter += key + ': ' + value + '\n';
                    else frontmatter += key + ': "' + value + '"\n';
                }
            }
        });

        if('' != frontmatter) frontmatter = '---\n' + frontmatter + '---\n';
        let text = frontmatter + ((cmsMarkdownEditor)? $('#editor').val() : tinymce.get("wysiwyg-editor").getContent());

        $.ajax({
            url: 'index.php',
            type: 'POST',
            data: { action: 'save', file: currentFile.get(), text: text, mode: cmsMode, client: $('#hugocms-client').val() },
            success: function(data)
            {
                if(data)
                {
                    if(exists(data.success) && !data.success)
                    {
                        if(data.hasOwnProperty('session_expired') || data.hasOwnProperty('invalid_session'))
                        {
                            alert(translate('Session is expired!'));
                            window.open('./', '_self');
                            return;
                        }
                        $('#message-dialog').showMessageDialog('error', translate('Open file'), translate('The file could not be edit!'), (data.hasOwnProperty('debug'))? data.debug : null);
                        return;
                    }

                    const result = JSON.parse(data);
                    if(!result.success)
                    {
                        $('#message-dialog').showMessageDialog('error', translate('Save file'), translate('The file could not be saved!'), (result.hasOwnProperty('debug'))? result.debug : null);
                    }
                    else
                    {
                        $('#editor-save').text(translate('Saved')).toggleClass('btn-primary btn-success');
                        setTimeout(function()
                        {
                            $('#editor-save').text(translate('Save')).toggleClass('btn-success btn-primary');
                        }, 2000);
                    }
                }
                if(null != fx) fx();
            },
            error: function()
            {
                console.log('editor-save ajax call error');
                $('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate('The server could not process the request!'));
            }
        });
        return false;
    }

    function closeEditor()
    {
        let editor = ace.edit($('.md-editor')[0]);
        editor.setValue('', 1);
        editor.resize(true);
        $('#editor-view').hide();
        $('#directory-view').show();
    }

    $('#close-no-saving').click(function()
    {
        if(confirm(translate("Close without saving!"))) closeEditor();
    });

    $('.close-editor').click(function()
    {
        closeEditor();
    });

    $('#editor-preview').click(function()
    {
        $.ajax({
            url: 'index.php',
            type: 'POST',
            data: { action: 'editor\\preview', client: $('#hugocms-client').val() },
            success: function(data)
            {
                if(data.success)
                {
                    window.open('preview/' + currentFile.getContentFile(), '_blank');
                }
                else
                {
                    $('#message-dialog').showMessageDialog('error', translate('Preview'), 'The preview could not be created!', (data.hasOwnProperty('debug'))? data.debug : null);
                }
            },
            error: function()
            {
                console.log('publish ajax call error');
                $('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate('The server could not process the request!'));
         }
        });
    });

    $('#spellcheck-dialog').dialog(
    {
        autoOpen: false,
        resizable: true,
        width: $('#editor-area').width(),
        height: $('#editor-area').height(),
        modal: true,
        title: 'Check spelling',
        open: function()
        {
            $(this).dialog('option', 'width', $('#editor-area').width());
            $(this).dialog('option', 'height', $('#editor-area').height());
            $('#spellcheck-textarea').width($('#spellcheck-dialog').width() - 10);
            $('#spellcheck-textarea').height($('#spellcheck-dialog').height() - 15);
            if(cmsMarkdownEditor) $('#spellcheck-textarea').val($('#editor').val());
            else $('#spellcheck-textarea').val(tinymce.get("wysiwyg-editor").getContent());
        },
        buttons:
        [{
            text: 'Take',
            class: 'dlg-btn-take',
            click: function()
            {
                if(cmsMarkdownEditor) ace.edit($('.md-editor')[0]).setValue($('#spellcheck-textarea').val(), 1);
                else tinymce.get("wysiwyg-editor").setContent($('#spellcheck-textarea').val());
                $(this).dialog("close");
            }
        },
        {
            text: 'Cancel',
            class: 'dlg-btn-cancel',
            click: function()
            {
                 $(this).dialog("close");
            }
        }]
    });

    $('#spellcheck-textarea').keydown(textareaSpecialKeys);

    $('#spellcheck').click(function()
    {
        $('#spellcheck-dialog').dialog('open');
        return false;
    });

    function translate(text)
    {
        if (typeof languageMap != "undefined")
        {
            const retv = languageMap[text];
            if(retv != undefined) return retv;
            //console.log("warning: missing translation: " + text);
        }
        return text;
    }

    function setLang(lang)
    {
        cmsLang = lang;
        document.cookie = 'hugocms_lang=' + lang + '; expires=' + new Date(2147483647 * 1000).toUTCString();
        $.ajax({
            url: 'index.php',
            type: 'POST',
            data: { action: 'editor\\writeUserSetup', data: { 'lang': lang }, client: $('#hugocms-client').val() },
            success: function(data)
            {
                changeLang();
                showMode();
                changeTinyMCELang();
            },
            error: function()
            {
                console.log('set lang ajax call error');
                $('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate('The server could not process the request!'));
            }
        });
    }

    function showMode()
    {
        const text = translate('Logged in as') + ' ' + cmsUser + ' ' + translate('in') + ' ';
        if('easy' === cmsMode)
        {
            $('#set-mode').text(translate('Normal mode'));
            $('#show-mode').text(text + translate('Easy mode'));
            $('#config').show();
        }
        else if('normal' === cmsMode)
        {
            $('#set-mode').text(translate('Admin mode'));
            $('#show-mode').text(text + translate('Normal mode'));
            $('#config').show();
        }
        else if('admin' === cmsMode)
        {
            $('#set-mode').text(translate('Easy mode'));
            $('#show-mode').text(text + translate('Admin mode'));
            $('#config').hide();
        }

        changeLang();
        $('#elfinder').elfinder('destroy').openFileBrowser();
    }

    $("#lang-en, #setup-lang-en").click(function()
    {
        languageMap = "";
        setLang("en");
    });

    $("#lang-de, #setup-lang-de").click(function()
    {
        $.getScript("./hugocms/js/editor.lang.de.js", function()
        {
            setLang("de");
        });
    });

    $("#lang-fr, #setup-lang-fr").click(function()
    {
        $.getScript("./hugocms/js/editor.lang.fr.js", function()
        {
            setLang("fr");
        });
    });

    function changeTinyMCELang()
    {
        tinymce.remove();
        initTinyMCE();
        if(cmsMarkdownEditor) $('#wysiwyg-editor-container').hide();
    }

    //Change language on start and on click
    function changeLang()
    {
        // Main menu buttons
		$('.navbar-nav').hide();
        $('#versioning').html(translate('Versioning'));
        $('#reset').html(translate('Restore'));
        $('#config').html(translate('Configuration'));
        $('#publish').html(translate('Publish'));
        $('#set-mode-button').html(translate('Change mode'));
        $('#set-normal-mode').html(translate('Normal mode'));
        $('#set-admin-mode').html(translate('Admin mode'));
        $('#set-easy-mode').html(translate('Easy mode'));
		$('.navbar-nav').show();
        // Editor buttons
        $('#close-no-saving').html(translate('Close without saving!'));
        $('#editor-save').html(translate('Save'));
        $('#editor-save-close').html(translate('Save and close'));
        $('#editor-save-publish').html(translate('Save and publish'));
        $('#editor-preview').html(translate('Preview'));
        $('#spellcheck').html(translate('Spellcheck'));
        $('#logout').text(translate('Logout'));
        $('#setup').text(translate('Setup'));
        // Dialogs
        $('#message-dialog-error').html(translate('An error has occurred'));
        $('.dlg-btn-save').text(translate('Save'));
        $('.dlg-btn-cancel').text(translate('Cancel'));
        $('.dlg-btn-take').text(translate('Take'));
        $('.dlg-btn-del').text(translate('Remove'));
        $('#spellcheck-dialog').dialog('option', 'title', translate('Check spelling'));
        $('#commit-msg-dialog').dialog('option', 'title', translate('Versioning'));
        $('#license-text').text(translate('License for'));
        $('#message').text(translate($('#message').text()));
        $('#new-project-dialog').dialog('option', 'title', translate('New project/ Domain'));
        $('#new-project-label').text(translate('Project name'));
        $('#new-project').text(translate('New project/ Domain'));
        // Setup
        $('#title').html(translate('Setup'));
        $('#username-label').html(translate('Login*'));
        $('#username-input').attr('placeholder', translate('Enter your login name'));
        $('#set-password-label').html(translate('Change password'));
        $('#password-label').html(translate('Password*'));
        $('#password-input').attr('placeholder', translate('Enter your password'));
        $('#password-retry-label').html(translate('Retry password*'));
        $('#password-retry-input').attr('placeholder', translate('Retry your password'));
        $('#use-purgecss-label').html(translate('Use minimized CSS'));
        $('#setup-submit-btn').html(translate('Save'));
        $('#setup-cancel-btn').html(translate('Cancel'));
        $('#easy-mode-label').html(translate('Easy mode'));
        $('#normal-mode-label').html(translate('Normal mode'));
        $('#admin-mode-label').html(translate('Admin mode'));
        $('#create-project-label').html(translate('Create project*'));
        $('#create-project-input').attr('placeholder', translate('Enter the name of the project'));
        $('#licensee-label').html(translate('Licensee'));
        $('#licensee-input').attr('placeholder', translate('Enter the name of the licensee'));
        $('#license-key-label').html(translate('License key'));
        $('#license-key-input').attr('placeholder', translate('Enter the license key'));
        $('#setup-ready').html(translate('The setup was successful and can now be completed.'));
        $('#setup-ready-button').html(translate('Continue'));
        //Editor for front matter variables
        $('#toggle-front-matter').text(translate('Show Front matter variables'));
        $('#editor-view-var-section').text(translate('Front matter variables'));
        $('#editor-view-var-key').html(translate('Variable name'));
        $('#editor-view-var-value').html(translate('Variable value'));
        $('#add-front-matter-var').text(translate('Add variable'));
        $('.remove-front-matter-var-btn').text(translate('Remove'));
        $('#use-front-matter-temp').text(translate('Use template'));

        elFinder.prototype.i18.en.messages['cmdcms_edit_md'] = "Open in Markdown editor";
        elFinder.prototype.i18.en.messages['cmdcms_edit_html'] = "Open in HTML editor";
        elFinder.prototype.i18.en.messages['untitled folder'] = 'new_folder';
        elFinder.prototype.i18.en.messages['untitled file'] = 'new_file.$1';
    }

    function logout()
    {
        $.ajax({
            url: 'index.php',
            type: 'POST',
            data: { action: 'editor\\logout', client: $('#hugocms-client').val() },
            success: function(data)
            {
                window.open('./', '_self');
            },
            error: function()
            {
                console.log('logout ajax call error');
                $('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate('The server could not process the request!'));
            }
        });
    }

    $('#logout').click(function()
    {
        $.ajax({
            url: 'index.php',
            type: 'POST',
            data: { action: 'editor\\logout', client: $('#hugocms-client').val() },
            success: function(data)
            {
                window.open('./', '_self');
            },
            error: function()
            {
                console.log('logout ajax call error');
                $('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate('The server could not process the request!'));
            }
        });
    });

    $('#new-project').click(function()
    {
        $('#new-project-dialog').dialog('open');
        return false;
    });

    $('#setup').click(function()
    {
        document.getElementById('easy-mode-input').checked = (('easy' == cmsMode)? 'checked' : '');
        document.getElementById('normal-mode-input').checked = (('normal' == cmsMode)? 'checked' : '');
        document.getElementById('admin-mode-input').checked = (('admin' == cmsMode)? 'checked' : '');
        $("#username-input").val(cmsUser);
        document.getElementById('use-purgecss').checked = cmsUsePurgeCSS;

        $.ajax({
            url: 'index.php',
            type: 'POST',
            data: { action: 'editor\\getLicense', client: $('#hugocms-client').val() },
            success: function(data)
            {
                if(data.hasOwnProperty('success'))
                {
                    if(false == data.success)
                    {
                        let error = 'The setup could not be read!';
                        if('' !== data.debug) error = ' ' + data.debug;
                        showSetupMessage('warning', translate(error));
                    }
                }
                else
                {
                    const licencee = data.licensee;
                    const licenseKey = data.licenseKey;
                    $('#licensee-input').val(licencee);
                    $('#license-key-input').val(licenseKey);
                }
                    
                $( '#directory-view' ).hide();
                $( '#setup-view' ).show();
                $( '.password-input' ).hide();
            },
            error: function()
            {
                console.log('get license data ajax call error');
                $('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate('The server could not process the request!'));
            }
        });
    });

    function showSetupMessage(style, message, debug = null)
    {
        $('#setup-error').append('<p class="bg-' + style + '" id="message" style="padding: 1em" id="empty-password">' + message + '</p>');
        if(null !== debug) $('#setup-error').append('<p class="bg-' + style + '" id="message" style="padding: 1em" id="empty-password">' + debug + '</p>');
    }

    $('#setup-submit-btn').click(function()
    {
        $('#setup-error').html('');
        const username = $('#username-input').val();
        const password = $('#password-input').val();
        const password_retry = $('#password-retry-input').val();
        const project_name = $('#create-project-input').val();
        cmsMode = $('input[name=mode]:checked').val();
        cmsUsePurgeCSS = document.getElementById('use-purgecss').checked;
        let data = {};

        if($('input[name=set-password]:checked').val() || !$('#set-password').is(':visible'))
        {
            if(username && password && password_retry)
            {
                if(password === password_retry)
                {
                    data = { 'username': username, 'password': password, 'lang': cmsLang, 'mode': cmsMode, 'purgecss': cmsUsePurgeCSS };
                }
                else
                {
                    showSetupMessage('danger', translate('The passwords do not match!'));
                    return false;
                }
            }
            else
            {
                showSetupMessage('danger', translate('Please fill out all fields with an asterisk!'));
                return false;
            }
        }
        else
        {
            if(username)
            {
                data = { 'username': username, 'lang': cmsLang, 'mode': cmsMode, 'purgecss': cmsUsePurgeCSS };
            }
            else
            {
                showSetupMessage('danger', translate('Please fill out all fields with an asterisk!'));
                return false;
            }
        }

        if($('#create-project-input').is(':visible'))
        {
            if(project_name)
            {
                data['project_name'] = project_name;
            }
            else
            {
                showSetupMessage('danger', translate('Project name cannot be empty!'));
                return false;
            }
        }

        const licensee = $('#licensee-input').val();
        const licenseKey = $('#license-key-input').val();
        if(licensee) data['licensee'] = licensee;
        if(licenseKey) data['licenseKey'] = licenseKey;

        $.ajax({
            url: 'index.php',
            type: 'POST',
            data: { action: 'editor\\writeUserSetup', data: data, client: $('#hugocms-client').val() },
            success: function(data)
            {
                if(data.success)
                {
                    //alert(translate('The setup was successful!'));
                    //if('running' == $('#hugocms-initial-setup').val()) logout();
                    //else window.open('./', '_self');
                    //localStorage.removeItem('/edit/-elfinder-toolbarhideselfinder');
                    window.open('./?action=setup_ready', '_self');
                }
                else
                {
                    showSetupMessage('danger', translate('The setup could not be performed!'), (data.hasOwnProperty('debug'))? translate(data.debug) : null);
                }
            },
            error: function()
            {
                console.log('setup ajax call error');
                $('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate('The server could not process the request!'));
            }
        });
        return false;
    });

    $('#setup-cancel-btn').click(function()
    {
        document.getElementById('set-password').checked = false;
        $('#setup-error').html('');
        $( '#directory-view' ).show();
        $( '#setup-view' ).hide();
    });

    function setMode(mode)
    {
        cmsMode = mode;
        $.ajax({
            url: 'index.php',
            type: 'POST',
            data: { action: 'editor\\writeUserSetup', data: { 'mode': cmsMode }, client: $('#hugocms-client').val() },
            error: function()
            {
                console.log('change mode ajax call error');
                $('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate('The server could not process the request!'));
            }
        });
        showMode();
    }

    $('#set-easy-mode').click(function()
    {
        setMode('easy');
    });

    $('#set-normal-mode').click(function()
    {
        setMode('normal');
    });

    $('#set-admin-mode').click(function()
    {
        setMode('admin');
    });

    $('#set-password').click(function()
    {
        if($('input[name=set-password]:checked').val()) $( '.password-input' ).show();
        else $( '.password-input' ).hide();
    });

    if('running' != $('#hugocms-initial-setup').val())
    {
        $.ajax({
            url: 'index.php',
            type: 'POST',
            data: { action: 'editor\\getSetup', client: $('#hugocms-client').val() },
            success: function(data)
            {
                if(data.hasOwnProperty('success'))
                {
                    if(false == data.success)
                    {
                        let error = 'The setup could not be read!';
                        if('' !== data.debug) error = ' ' + data.debug;
                        $('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate(error));
                        return;
                    }
                }

                if(data.lang == "de")
                {
                    $.getScript("./hugocms/js/editor.lang.de.js", function()
                    {
                        changeLang();
                    });
                }
                else if(data.lang == "fr")
                {
                    $.getScript("./hugocms/js/editor.lang.fr.js", function()
                    {
                        changeLang();
                    });
                }
                else
                {
                    changeLang();
                }
                cmsMode = data.mode;
                cmsUser = data.login;
                cmsLang = data.lang;
                cmsUsePurgeCSS = ('true' === data.purgecss || '1' === data.purgecss)? true : false;

                $.ajax({
                    url: 'index.php?action=template',
                    type: 'POST',
                    data: { client: $('#hugocms-client').val() },
                    success: function(data)
                    {
                        cmsFrontMatterTemplate = parseFrontMatterVariables(data);
                        if(false === cmsFrontMatterTemplate)
                        {
                            $('#message-dialog').showMessageDialog('error', translate('Front matter template'), translate('The front matter template could not be read!'));
                        }

                        showMode();
                        //Initialisierung der Editoren (Markdown und WYSIWYG)
                        $('#editor').markdownEditor();
                        initTinyMCE();
                    },
                    error: function()
                    {
                        $('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate('The server could not process the request!'));
                        showMode();
                    }
                });
            },
            error: function()
            {
                console.log('get lang ajax call error');
                changeLang();
                showMode();
                $('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate('The server could not process the request!'));
            }
        });
    }
    else
    {
        changeLang();
    }
});
