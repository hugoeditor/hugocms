<!DOCTYPE html>
<html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo $title ?> - HugoCMS</title>
        <link href="./hugocms/css/bootstrap.min.css" rel="stylesheet">
        <style>
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
        </style>
    </head>
    <body>
        <div id="view-header" class="container" <?php if($setup) echo 'style="display:none;"'; ?>>
            <div class="row">
                <div class="col-md-6" style="margin-top: 10px;"><?php if(!empty($license_error)) echo '<span class="bg-danger">'.$license_error.'</span>'; else echo '<span class="text-muted" id="license-text"></span> <span class="text-muted">'.$license_user.'</span>'; ?></div>
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
        <div class="container">
            <div class="" tabindex="-1" role="dialog">
              <div class="modal-dialog" role="document">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 id="title" class="modal-title"></h5>
                    <p class="bg-danger" style="padding: 1em <?php if(empty($error_msg)) echo '; display:none;'; ?>" id="empty-password"><span id="error-msg"></span></p>
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
    <script src="./hugocms/js/jquery.min.js"></script>
    <script>
        var cmsLoginLang = "<?php if(isset($_COOKIE['hugocms_lang'])) echo $_COOKIE['hugocms_lang']; ?>";

        $('#username').focus();

        function toggleLanguageMenu()
        {
            document.getElementById("language-menu").classList.toggle("show");
        }

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
            $('#error-msg').html(translate('<?php echo $error_msg; ?>'));
        }
        if(cmsLoginLang == "de")
        {
            $.getScript("./hugocms/js/login.lang.de.js", function()
            {
                changeLang();
            });
        }
        else if(cmsLoginLang == "fr")
        {
            $.getScript("./hugocms/js/login.lang.fr.js", function()
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
            $.getScript("./hugocms/js/login.lang.de.js", function()
            {
                setLang("de");
                changeLang();
            });
        });

        $("#lang-fr, #setup-lang-fr").click(function()
        {
            $.getScript("./hugocms/js/login.lang.fr.js", function()
            {
                setLang("fr");
                changeLang();
            });
        });

    </script>
</html>
