pimcore.registerNS("pimcore.plugin.ImportBundle");

pimcore.plugin.ImportBundle = Class.create({

    initialize: function () {
        document.addEventListener(pimcore.events.pimcoreReady, this.pimcoreReady.bind(this));
    },

    pimcoreReady: function (e) {
        // alert("ImportBundle ready!");
    }
});

var ImportBundlePlugin = new pimcore.plugin.ImportBundle();
