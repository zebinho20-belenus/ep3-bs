(function() {

    $(document).ready(function() {

        var language = $("html").attr("lang");
        var basePath = $("#logo").attr("href");

        if (language === "en-US") {
            language = undefined;
        } else if (language) {
            language = language.split("-")[0];
        }

        tinymce.init({
            "selector": ".wysiwyg-editor",
            "language": language,
            "plugins": "image link",
            "content_css": basePath + "css/tinymce/default.min.css",
            "toolbar": "undo redo | styles | bold italic | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image",
            "menubar": false,
            "statusbar": false,
            "relative_urls": false,
            "promotion": false,
            "invalid_elements": "script,iframe,object,embed,form",

            file_picker_callback : function(callback, value, meta) {

                var w = window,
                d = document,
                e = d.documentElement,
                g = d.getElementsByTagName("body")[0],
                x = w.innerWidth || e.clientWidth || g.clientWidth,
                y = w.innerHeight|| e.clientHeight|| g.clientHeight;

                var cmsURL = basePath + "vendor/filemanager/index.html?&langCode=" + (tinymce.activeEditor.options.get('language') || 'en');

                if (meta.filetype == "image") {
                    cmsURL = cmsURL + "&type=images";
                }

                tinymce.activeEditor.windowManager.openUrl({
                    url : cmsURL,
                    title : "Filemanager",
                    width : Math.round(x * 0.8),
                    height : Math.round(y * 0.8)
                });

                window.tinymceFilePickerCallback = callback;
            }
        });

    });

})();
