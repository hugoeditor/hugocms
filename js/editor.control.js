$(document).ready(function()
{
	$('#editor').markdownEditor();

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
		resizable: false,
		width: 'auto',
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
		if(null != debug) $('#message-dialog-debug').html('<pre>' + debug + '</pre>').css('display', '');
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
						url: 'hugocms/editor.control.php',
						type: 'POST',
						data: { action: 'editor\\versioning', data: { 'commsg': commit_msg } },
						success: function(data)
						{
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

	$('#versioning').click(function()
	{
		$('#commit-msg-dialog').dialog('open');
		return false;
	});

	$('#reset').click(function()
	{
		$.ajax({
			url: 'hugocms/editor.control.php',
			type: 'POST',
			data: { action: 'editor\\restore' },
			success: function(data)
			{
				let message = (data.success)? translate('The changes have been rolled back.') : translate('The changes could not be rolled back!');
				$('#message-dialog').showMessageDialog( (data.success)? 'success' : 'error', translate('Restore'), message, (data.hasOwnProperty('debug'))? data.debug : null);
				$('#navi, #directory-list').refreshDirectoryView();
			},
			error: function()
			{
				console.log('versioning ajax call error');
				$('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate('The server could not process the request!'));
            }
		});
		return false;
	});

    $('#config-dialog').dialog(
	{
		autoOpen: false,
		resizable: false,
		width: 'auto',
		height: 'auto',
		modal: true,
		title: 'config.json',
		position: { my: "left top", at: "left bottom", of: '#versioning' },
		open: function()
		{
			$(this).css('maxWidth', window.innerWidth);
			$.ajax({
				url: 'hugocms/editor.load.php',
				type: 'POST',
				data: { file: '/../config.json' },
				success: function(data)
				{
					$('#config-textarea').val(data);
				},
				error: function()
				{
					console.log('config-dialog ajax call error');
					$('#config-dialog').dialog('close');
					$('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate('The server could not process the request!'));
				}
			});
		},
		buttons:
		[{
			text: 'Save',
			class: 'dlg-btn-save',
			click: function()
			{
				$.ajax({
					url: 'hugocms/editor.save.php',
					type: 'POST',
					data: { file: '/../config.json', text: $('#config-textarea').val()  },
					success: function(data)
					{
						if(data)
						{
							const result = JSON.parse(data);
							if(!result.success)
							{
								$('#message-dialog').showMessageDialog('error', translate('Save configuration file'), translate('The file could not be saved!'), (result.hasOwnProperty('debug'))? result.debug : null);
							}
						}
					},
					error: function()
					{
						console.log('config-save ajax call error');
						$('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate('The server could not process the request!'));
		            }
				});
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
	$('#config-textarea').keydown(textareaSpecialKeys);

	$('#config').click(function()
	{
		$('#config-dialog').dialog('open');
		return false;
	});

	$('#publish').click(function()
	{
		$.ajax({
			url: 'hugocms/editor.control.php',
			type: 'POST',
			data: { action: 'editor\\publish' },
			success: function(data)
			{
				let message = (data.success)? translate('The website has been published.') : translate('The website could not be published!');
				$('#message-dialog').showMessageDialog( (data.success)? 'success' : 'error', translate('Publish'), message, (data.hasOwnProperty('debug'))? data.debug : null);
			},
			error: function()
			{
				console.log('publish ajax call error');
				$('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate('The server could not process the request!'));
         }
		});
		return false;
	});

    $('#confirm-del-dialog').dialog(
	{
		autoOpen: false,
		resizable: false,
		width: 'auto',
		height: 'auto',
		modal: true,
		title: 'Remove',
		position: { my: "left top", at: "left bottom", of: '#mkdir' },
		buttons:
		[{
			text: 'Remove',
			class: 'dlg-btn-del',
			click: function()
			{
				$.ajax({
					url: 'hugocms/editor.control.php',
					type: 'POST',
					data: { action: 'editor\\removeTarget', data: { 'target': $('#confirm-del-file').text() } },
					success: function(data)
					{
						if(!data.success)
						{
							$('#message-dialog').showMessageDialog('error', translate('Remove file/directory'), translate('The directory or file could not be deleted!'), (data.hasOwnProperty('debug'))? data.debug : null);
						}
						$('#navi, #directory-list').refreshDirectoryView();
					},
					error: function()
					{
						console.log('removeTarget ajax call error');
						$('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate('The server could not process the request!'));
		            }
				});
				$(this).dialog("close");
				return false;
			}
		},
		{
			text: 'Cancel',
			class: 'dlg-btn-cancel',
			click: function()
			{
				$(this).dialog("close");
				return false;
			}
		}]
	});

    $('#rename-dialog').dialog(
	{
		autoOpen: false,
		resizable: false,
		width: 'auto',
		height: 'auto',
		modal: true,
		title: 'Rename file',
		position: { my: "left top", at: "left bottom", of: '#mkdir' },
		close: function()
		{
			$('#filename').val('');
		},
		buttons:
		[{
			text: 'Rename',
			class: 'dlg-btn-rename',
			click: function()
			{
				let target = $('#rename-dialog-target').text();
				let filename = $('#rename-dialog-filename').val();
				$('#rename-dialog-error').remove();

				if(filename)
				{
					$.ajax({
						url: 'hugocms/editor.control.php',
						type: 'POST',
						data: { action: 'editor\\renameTarget', data: { 'target': target, 'filename': currentDir.toString() + filename } },
						success: function(data)
						{
							if(!data.success)
							{
								$('#message-dialog').showMessageDialog('error', translate('Rename file/directory'), translate('The file or directory could not be renamed!'), (data.hasOwnProperty('debug'))? data.debug : null);
							}
							$('#navi, #directory-list').refreshDirectoryView();
						},
						error: function()
						{
							console.log('rename ajax call error');
							$('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate('The server could not process the request!'));
			            }
					});
					$(this).dialog("close");
					return false;
				}

				$('#rename-dialog').prepend('<p id="rename-dialog-error">' + translate('Please enter a file name!') + '</p>');
			}
		},
		{
			text: 'Cancel',
			class: 'dlg-btn-cancel',
			click: function()
			{
				$('#rename-dialog-error').remove();
				$(this).dialog("close");
				return false;
			}
		}]
	});

	$.fn.listDirectory = function()
	{
		var directoryList = $(this);
		$.ajax(
		{
			url: 'hugocms/editor.control.php',
			type: 'POST',
			data: { action: 'editor\\scanCurrentDirectory', data: { 'path': currentDir.toString() } },
			success: function(data)
			{
				let i = 0;
				directoryList.html('');
				for(const fileInfo of data)
				{
					let tr = '<tr><td><a class="' + ((fileInfo.dir)? 'dir' : 'file')  + '-link" href="' + currentDir.toString() + fileInfo.name  + '">';
					tr += (fileInfo.dir)? '<strong>' + fileInfo.name + '</strong>' : fileInfo.name; 
					tr += '</a></td><td>';
					tr += (fileInfo.dir)? translate('Directory') : translate('File'); 
					tr += '</td><td>';
					switch(fileInfo.permission)
					{
					case 0:
						tr += translate('No permission');
						break;
					case 1:
						tr += translate('Read only');
						break;
					case 2:
						tr += translate('Readable and writable');
						break;
					}
					tr += '</td><td>'
					tr +='<button type="button" value="' + fileInfo.name  + '" class="btn btn-default btn-xs rename-button">' + translate('Rename') + '</button>';
					tr +='<button type="button" value="' + fileInfo.name  + '" class="btn btn-default btn-xs del-button">' + translate('Remove') + '</button>';
					tr += '</td></tr>';
					directoryList.append(tr);
				}

				$('.dir-link').click(function()
				{
					currentDir.setPath($(this).attr('href'));
					$('#navi, #directory-list').refreshDirectoryView();
					return false;
				});

				$('.file-link').click(function()
				{
					currentFile.set($(this).attr('href'));
					$('#directory-view').hide();
					$('#editor-view').show();
					$('#editor-view-filename').text('content' + currentFile.get());
					$.ajax(
					{
						url: 'hugocms/editor.load.php',
						type: 'POST',
						data: { file: currentFile.get() },
						success: function(data)
						{
							ace.edit($('.md-editor')[0]).setValue(data, 1);
						},
						error: function()
						{
							console.log('loadfile ajax call error');
			            }
					});
					return false;
				});

				$('.rename-button').click(function()
				{
					$('#rename-dialog-target').text(currentDir.toString() + $(this).val());
					$('#rename-dialog').dialog('open');
					return false;
				});

				$('.del-button').click(function()
				{
					$('#confirm-del-file').text(currentDir.toString() + $(this).val());
					$('#confirm-del-dialog').dialog('open');
					return false;
				});
			},
			error: function()
			{
				console.log('listDirectory ajax call error');
            }
		});
		return false;
	}

	$.fn.navi = function()
	{
		const path = currentDir.getPath();
		let value = '';
		$(this).html('<button class="chdir-button btn btn-default" value="/">content / </button>');
		for(const dir of path)
		{
			value += '/' + dir;
			if(dir) $(this).append('<button class="chdir-button btn btn-default" value="' + value + '">' + dir + ' / </button>');
		}

		$('.chdir-button').click(function()
		{
			const path = $(this).val();
			if(path) currentDir.setPath(path);
			$('#navi, #directory-list').refreshDirectoryView();
		});
	}

	var currentDir = {
		path: [],
		setPath: function(path)
		{
			this.path = path.split('/').filter(dir => dir);
		},
		getPath: function()
		{
			return this.path;
		},
		toString: function()
		{
			let path = '/';
			for(const dir of this.path)
			{
				if(dir) path += dir + '/'
			}
			return path;
		}
	};
	currentDir.setPath('/');
	
	$.fn.refreshDirectoryView = function()
	{
		$(this).eq(0).navi();
		$(this).eq(1).listDirectory();
	}
	$('#navi, #directory-list').refreshDirectoryView();

    $('#mkdir-dialog').dialog(
	{
		autoOpen: false,
		resizable: false,
		width: 'auto',
		height: 'auto',
		modal: true,
		title: 'New directory',
		position: { my: "left top", at: "left bottom", of: '#mkdir' },
		close: function()
		{
			$('#dirname').val('');
		},
		buttons:
		[{
			text: 'Create',
			class: 'dlg-btn-create',
			click: function()
			{
				const dirname = $('#dirname').val();
				$('#mkdir-dialog-error').remove();

				if(dirname)
				{
					$.ajax({
						url: 'hugocms/editor.control.php',
						type: 'POST',
						data: { action: 'editor\\makeDirectory', data: { 'dirname': currentDir.toString() + dirname } },
						success: function(data)
						{
							if(!data.success)
							{
								$('#message-dialog').showMessageDialog('error', translate('New directory'), translate('The directory could not be created!'), (data.hasOwnProperty('debug'))? data.debug : null);
							}
							$('#navi, #directory-list').refreshDirectoryView();
						},
						error: function()
						{
							console.log('mkdir ajax call error');
							$('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate('The server could not process the request!'));
			            }
					});
					$(this).dialog("close");
					return false;
				}

				$('#mkdir-dialog').prepend('<p id="mkdir-dialog-error">' + translate('Please enter a directory name!')  + '</p>');
			}
		},
		{
			text: 'Abbrechen',
			class: 'dlg-btn-cancel',
			click: function()
			{
				$('#mkdir-dialog-error').remove();
				$(this).dialog("close");
				return false;
			}
		}]
	});

	$('#mkdir').click(function()
	{
		$('#mkdir-dialog').dialog('open');
		return false;
	});

    $('#new-file-dialog').dialog(
	{
		autoOpen: false,
		resizable: false,
		width: 'auto',
		height: 'auto',
		modal: true,
		title: 'New file',
		position: { my: "left top", at: "left bottom", of: '#mkdir' },
		close: function()
		{
			$('#filename').val('');
		},
		buttons:
		[{
			text: 'Anlegen',
			class: 'dlg-btn-create',
			click: function()
			{
				let filename = $('#filename').val();
				$('#new-file-dialog-error').remove();
				if(!filename.endsWith('.md')) filename += '.md';

				if(filename)
				{
					$.ajax({
						url: 'hugocms/editor.control.php',
						type: 'POST',
						data: { action: 'editor\\newFile', data: { 'filename': currentDir.toString() + filename } },
						success: function(data)
						{
							if(!data.success)
							{
								$('#message-dialog').showMessageDialog('error', translate('New file'), translate('The directory could not be created!'), (data.hasOwnProperty('debug'))? data.debug : null);
							}
							$('#navi, #directory-list').refreshDirectoryView();
						},
						error: function()
						{
							console.log('new-file ajax call error');
							$('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate('The server could not process the request!'));
			            }
					});
					$(this).dialog("close");
					return false;
				}

				$('#new-file-dialog').prepend('<p id="new-file-dialog-error">' + translate('Please enter a file name!') + '</p>');
			}
		},
		{
			text: 'Abbrechen',
			class: 'dlg-btn-cancel',
			click: function()
			{
				$('#new-file-dialog-error').remove();
				$(this).dialog("close");
				return false;
			}
		}]
	});

	$('#new-file').click(function()
	{
		$('#new-file-dialog').dialog('open');
		return false;
	});

	var currentFile = {
		filename: '',
		set: function(file)
		{
			this.filename = file;
		},
		get: function()
		{
			return this.filename;
		}
	};

	$('.save-button').click(function()
	{
		$.ajax({
			url: 'hugocms/editor.save.php',
			type: 'POST',
			data: { file: currentFile.get(), text: $('#editor').val()  },
			success: function(data)
			{
				if(data)
				{
					const result = JSON.parse(data);
					if(!result.success)
					{
						$('#message-dialog').showMessageDialog('error', translate('Save file'), translate('The file could not be saved!'), (result.hasOwnProperty('debug'))? result.debug : null);
					}
					else
					{
						$('#editor-save').text('Gespeichert!').toggleClass('btn-primary btn-success');
						setTimeout(function()
						{
							$('#editor-save').text('Speichern').toggleClass('btn-success btn-primary');
						}, 2000);
					}
				}
			},
			error: function()
			{
				console.log('editor-save ajax call error');
				$('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate('The server could not process the request!'));
            }
		});
		return false;
	});

	$('.close-editor').click(function()
	{
		let editor = ace.edit($('.md-editor')[0]);
		editor.setValue('', 1);
		editor.resize(true);
		$('#editor-view').hide();
		$('#directory-view').show();
	});

	$('#editor-preview').click(function()
	{
		$.ajax({
			url: 'hugocms/editor.control.php',
			type: 'POST',
			data: { action: 'editor\\preview' },
			success: function(data)
			{
				if(data.success)
				{
					window.open('/edit/preview' + currentFile.get().split('.md')[0], '_blank');
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
			$('#spellcheck-textarea').val($('#editor').val());
		},
		buttons:
		[{
			text: 'Take',
			class: 'dlg-btn-take',
			click: function()
			{
				ace.edit($('.md-editor')[0]).setValue($('#spellcheck-textarea').val(), 1);
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
		$.ajax({
			url: 'hugocms/editor.control.php',
			type: 'POST',
			data: { action: 'editor\\setLang', data: { 'lang': lang } },
			error: function()
			{
				console.log('set lang ajax call error');
				$('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate('The server could not process the request!'));
            }
		});
	}

	function getLang(lang)
	{
		$.ajax({
            async: false,
			url: 'hugocms/editor.control.php',
			type: 'POST',
			data: { action: 'editor\\getLang' },
			success: function(data)
			{
				if(data.lang == "de")
				{
					$.getScript("./js/editor.lang.de.js", function()
					{
						changeLang();
					});
				}
				else if(data.lang == "fr")
				{
					$.getScript("./js/editor.lang.fr.js", function()
					{
						changeLang();
					});
				}
				else
				{
					changeLang();
				}
			},
			error: function()
			{
				console.log('get lang ajax call error');
				changeLang();
				$('#message-dialog').showMessageDialog('error', translate('Connection to the server'), translate('The server could not process the request!'));
            }
		});
	}

	$("#lang-en").click(function()
	{
		languageMap = "";
		changeLang();
		setLang("en");
	});

	$("#lang-de").click(function()
	{
		$.getScript("./js/editor.lang.de.js", function()
		{
			changeLang();
			setLang("de");
		});
	});

	$("#lang-fr").click(function()
	{
		$.getScript("./js/editor.lang.fr.js", function()
		{
			changeLang();
			setLang("fr");
		});
	});

	getLang();

	function changeLang()
	{
		$('#navi, #directory-list').refreshDirectoryView();
		$('#versioning').html(translate('Versioning'));
		$('#reset').html(translate('Restore'));
		$('#config').html(translate('Configuration'));
		$('#publish').html(translate('Publish'));
		$('#mkdir').html(translate('New directory'));
		$('#new-file').html(translate('New file'));
		$('#th-filename').html(translate('File name'));
		$('#th-filetype').html(translate('File type'));
		$('#th-access').html(translate('Access rights'));
		$('#th-action').html(translate('Action'));
		$('#close-no-saving').html(translate('Close without saving!'));
		$('#editor-save').html(translate('Save'));
		$('#editor-save-close').html(translate('Save and close'));
		$('#editor-preview').html(translate('Preview'));
		$('#spellcheck').html(translate('Spellcheck'));
		$('#message-dialog-error').html(translate('An error has occurred'));
		$('#dirname-label').html(translate('Directory name'));
		$('#filename-label').html(translate('File name'));
		$('#new-filenmae-label').html(translate('New file name'));
		$('.dlg-btn-save').text(translate('Save'));
		$('.dlg-btn-cancel').text(translate('Cancel'));
		$('.dlg-btn-take').text(translate('Take'));
		$('.dlg-btn-del').text(translate('Remove'));
		$('#confirm-del-dialog').dialog('option', 'title', translate('Remove'));
		$('#rename-dialog').dialog('option', 'title', translate('Rename file'));
		$('dlg-btn-rename').text(translate('Rename'));
		$('dlg-btn-create').text(translate('Create'));
		$('#new-file-dialog').dialog('option', 'title', translate('New file'));
		$('#mkdir-dialog').dialog('option', 'title', translate('New directory'));
		$('#spellcheck-dialog').dialog('option', 'title', translate('Check spelling'));
		$('#commit-msg-dialog').dialog('option', 'title', translate('Versioning'));
		$('#license-text').text(translate('License for'));
	}
});

