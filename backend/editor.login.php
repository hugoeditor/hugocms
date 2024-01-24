<!DOCTYPE html>
<html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo $title ?> - HugoCMS</title>
        <link href="./css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body>
        <div id="view-header" class="container" <?php if($setup) echo 'style="display:none;"'; ?>>
            <div class="row">
                <div class="col-md-6" style="margin-top: 10px;"><?php if(!empty($license_error)) echo '<span class="bg-danger">'.$license_error.'</span>'; else echo '<span class="text-muted" id="license-text"></span> <span class="text-muted">'.$license_user.'</span>'; ?></div>
                <div class="col-md-6 text-right" style="margin-top: 10px;"><span id="lang-en">English</span> / <span id="lang-fr">Français</span> / <span id="lang-de">Deutsch</span></div>
            </div>
        </div>
        <div class="container">
            <div class="" tabindex="-1" role="dialog">
              <div class="modal-dialog" role="document">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 id="title" class="modal-title"></h5>
                    <span><?php echo $error_msg; ?></span>
                </div>
                <div class="modal-body">
                <form action="./" method="POST">
                    <div class="form-group">
                        <label id="username-label" for="username"></label>
                        <input type="text" class="form-control" id="username" name="username" placeholder="">
                    </div>
                    <div class="form-group">
                        <label id="password-label" for="password"></label>
                        <input type="password" class="form-control" id="password" name="password" placeholder="">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button id="submit-btn" type="submit" class="btn btn-primary"></button>
                  </div>
                </form>
                </div>
              </div>
            </div>
        </div>
    </body>
    <script src="./js/jquery.min.js"></script>
    <script>
        var cmsLoginLang = "<?php if(isset($_COOKIE['hugocms_lang'])) echo $_COOKIE['hugocms_lang']; ?>";

        function translate(text)
        {
            if (typeof languageMap != "undefined")
            {
                const retv = languageMap[text];
                if(retv != undefined) return retv;
            }
            return text;
        }

        function setLang(lang)
        {
            cmsLoginLang = lang;
            document.cookie = 'hugocms_lang=' + lang;
        }

        function changeLang()
        {
            $('#title').html(translate('HugoCMS Login'));
            $('#username-label').html(translate('Login'));
            $('#username').attr('placeholder', translate('Enter your login name'));
            $('#password-label').html(translate('Password'));
            $('#password').attr('placeholder', translate('Enter your password'));
            $('#submit-btn').html(translate('Submit'));
        }
        if(cmsLoginLang == "de")
        {
            $.getScript("./js/editor.lang.de.js", function()
            {
                changeLang();
            });
        }
        else if(cmsLoginLang == "fr")
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

        $("#lang-en, #setup-lang-en").click(function()
        {
            languageMap = "";
            setLang("en");
            changeLang();
        });

        $("#lang-de, #setup-lang-de").click(function()
        {
            $.getScript("./js/editor.lang.de.js", function()
            {
                setLang("de");
                changeLang();
            });
        });

        $("#lang-fr, #setup-lang-fr").click(function()
        {
            $.getScript("./js/editor.lang.fr.js", function()
            {
                setLang("fr");
                changeLang();
            });
        });

    </script>
</html>
