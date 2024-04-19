$(document).ready(function()
{
	$('#editor').markdownEditor();

	$('#open_spellcheck').click(function()
	{
        $('#spellcheck_dialog').dialog(
		{
			resizable: true,
			width: $('#editor_area').width(),
			height: $('#editor_area').height(),
			modal: true,
			title: 'Rechtschreibung überprüfen',
			open: function()
					{
						$('#spellcheck_textarea').width($('#spellcheck_dialog').width() - 10);
						$('#spellcheck_textarea').height($('#spellcheck_dialog').height() - 15);
						$('#spellcheck_textarea').val($('#editor').val());
					},
			resize: function(event, ui)
					{
						$('#spellcheck_textarea').width($('#spellcheck_dialog').width() - 10);
						$('#spellcheck_textarea').height($('#spellcheck_dialog').height() - 15);
					},
			buttons:
				[{
					text: 'Übernehmen',
					click: function()
							{
								var editor = ace.edit($('.md-editor')[0]);
								editor.setValue($('#spellcheck_textarea').val(), 1);
								$(this).dialog("close");
							}
				},
				{
					text: 'Abbrechen',
					click: function()
							{
					             $(this).dialog("close");
							}
				}]
		});
        return false;
	});
});

