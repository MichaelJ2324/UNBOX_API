Backbone.View.prototype.close = function(){
    this.$el.html("");
    this.unbind();
}
var UNBOXAPI = UNBOXAPI || {};

UNBOXAPI.Queue = function(functions,callback){
    var running = false;
    var queue = functions;
    var callback = callback;
    var queued = queue.length;
    this.process = function(){
        running = true;
        for(x=0;x<queued;x++){
            queue[x].f(queue[x].p).always(this.down);
        }
    }
    this.down = function(){
        queued--;
        if (queued==0){
            running = false;
            callback();
        }
    }
}
UNBOXAPI.Translator = function(){
    var available_langs = [
        'en'
    ];
    var language = null;
    var currentContext = null;
    this.translate = function(label,context){
        if (typeof context == 'undefined'){
            context = currentContext;
        }
        var string = label;
        //console.log(context);
        if (typeof context == 'object' && context.hasOwnProperty('labels')){
            string = context.labels.getValue(label);
            //console.log(string);
        }
        return string;
    };
    this.setLang = function(lang){
        if (available_langs.indexOf(lang)!==-1){
            language = lang;
        }
    }
    this.setContext = function(context){
        currentContext = context;
    }
}
UNBOXAPI.Global = {
    ajaxURL: "api/",
    Utils: {
        Loading: {
            start: function (data) {
                var notice = new UNBOXAPI.Models.Notices;
                notice.set({
                    type: "loading",
                    level: "info",
                    show: true,
                    message: data
                });
                UNBOX.collections.notices.log(notice);
                return notice;
            },
            done: function (model){
                var notice = UNBOX.collections.notices.done(model);
                $("body").css("cursor", "auto");
            }
        },
        log: function(response){
            var notice = new UNBOXAPI.Models.Notices;
            notice.set({
                type: "warning",
                level: "debug",
                show: true,
                message: response.status + ": " + response.statusText
            });
            UNBOX.collections.notices.log(notice);
        },
        notice: function(message,type){
            var notice = new UNBOXAPI.Models.Notices;
            notice.set({
                type: type,
                level: "info",
                show: true,
                message: message
            });
            UNBOX.collections.notices.log(notice);
        }
    },
    Login: {
        Google: {
            loginButton: function(authResult) {
                if (typeof gapi !== 'undefined') {
                    if (typeof authResult == 'undefined') {
                        var params = {
                            'theme': 'light',
                        };
                        gapi.signin.render('gLogin', params);
                    } else {
                        if (authResult['status']['signed_in']) {
                            document.getElementById('gLoginWrapper').setAttribute('style', 'display: none');
                            gapi.client.load('plus', 'v1', UNBOXAPI.Global.Login.Google.apiClientLoaded);
                        } else {
                            console.log(authResult);
                        }
                    }
                }
            },
            apiClientLoaded: function () {
                var request = gapi.client.plus.people.get({
                    'userId': 'me'
                });
                request.execute(UNBOXAPI.Global.Login.Google.handleProfile);
            },
            handleProfile: function (resp) {
                console.log(resp);
                console.log('Retrieved profile for:' + resp.displayName);
            },
            ReCaptcha: {
                render: function(element) {
                    if (typeof grecaptcha !== 'undefined') {
                        return grecaptcha.render(element, {
                            'sitekey': '6Ldh2QMTAAAAAIYd2mUJBSek7MaBWc3X8yYPv6bE',
                            'theme': 'light'
                        });
                    }
                }
            }
        }
    },
    Cookie: {
        set: function(name, value, exdays) {
            var d = new Date();
            d.setTime(d.getTime() + (exdays*24*60*60*1000));
            var expires = "expires="+d.toUTCString();
            document.cookie = cname + "=" + cvalue + "; " + expires;
        },
        get: function(name) {
            name += "=";
            var ca = document.cookie.split(';');
            for(var i=0; i<ca.length; i++) {
                var c = ca[i];
                while (c.charAt(0)==' ') c = c.substring(1);
                if (c.indexOf(name) == 0) return c.substring(name.length, c.length);
            }
            return null;
        }
    },
};
UNBOXAPI.Views = {};
UNBOXAPI.Views.RelateField = Backbone.View.extend({
    initialize: function(options){
        this.options = options || {};
        this.url = this.options.url || null;
        this.defaultDisabled = this.options.disable || false;
        this.filters = this.options.filters || {};
        this.fetchOnSelect = this.options.fetchOnSelect || false;
        if (this.url==null){
            this.url = this.model.url()+"/filter";
        }
        _.bindAll(this, 'disable','updateModel','resultsHandler','dataHandler');
        if (this.initDependent()){
            this.render();
        }
    },
    initDependent: function(){
        return true;
    },
    render: function(){
        this.$el.select2({
            ajax: {
                url: this.url,
                dataType: 'json',
                quietMillis: 250,
                delay: 500,
                data: this.dataHandler,
                results: this.resultsHandler,
                cache: true
            },
            disabled: this.defaultDisabled,
            minimumInputLength: 1
        });
        this.$el.on("change",this.updateModel);
        return this;
    },
    dataHandler: function(term, page){
        var filters = {
            name: term
        };
        for (var key in this.filters) {
            if (this.filters.hasOwnProperty(key)) {
                var obj = this.filters[key];
                if (typeof obj == 'object'){
                    filters[key] = obj.get('id');
                }else{
                    filters[key] = obj;
                }
            }
        }
        return {
            filters: filters,
            view: "select2",
            offset: (page-1)*20
        };
    },
    resultsHandler: function(data,page){
        //TODO: Adding in pagination and auto-scroll
        return {
            results: data.records
        };
    },
    disable: function(disable) {
        if (typeof disable == 'undefined' || disable==null || disable==true){
            disable = 'disabled';
            $(this.el).attr('disabled', disable);
        }else{
            $(this.el).removeAttr('disabled');
        }
    },
    updateModel: function(e){
        var value = $(e.currentTarget).val();
        this.model.set({
            id: value
        });
        if (this.fetchOnSelect && !(value==null || typeof value=='undefined')){
            UNBOXAPI.Models.Utils.fetch({
                model: this.model
            });
        }
    }
});
UNBOXAPI.Views.DependentRelateField = UNBOXAPI.Views.Global.RelateField.extend({
    initDependent: function(){
        this.parent = this.options.parent || null;
        _.bindAll(this,"updateURL");
        if (this.parent == null){
            console.log("No parent provided for Depedent Relate Field");
            return false;
        }else{
            this.parent.on("change:id",this.updateURL)
        }
        this.defaultDisabled = this.options.disable || true;
        return true;
    },
    updateURL: function(){
        var id = this.parent.get('id');
        this.url = UNBOXAPI.Global.ajaxURL + this.parent.name + "/"+id+"/related/" + this.model.name + "/filter";
        this.defaultDisabled = false;
        $(this.el).select2("destroy");
        this.render();
    }
});
UNBOXAPI.Views.Form = Backbone.View.extend({
    events: {
        "focusout input": "updateModel",
        "focusout textarea": "updateModel",
        "change select": "updateModel",
        "change .select2": "updateModel",
        "click .save": "save",
        "click .clear": "clear"
    },
    initialize: function(options){

    },
    syncForm: function(){
        var inputs = this.$el.find(":input");
        for(var x=0;x<inputs.length;x++) {
            var $input = $(inputs[x]);
            if ($input.attr('id').indexOf("autogen")==-1) {
                console.log($input.val());
            }
        }

    },
    updateModel: function(e) {
        var changed = e.currentTarget;
        var value = $(e.currentTarget).val();
        var obj = {};
        if (changed.name!=="") {
            obj[changed.name] = value;
            this.model.set(obj);
        }
    }
});