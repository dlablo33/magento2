define([
    'jquery'
], function ($) {
    'use strict';

    return function () {
        console.log('✅ zipcode-reload.js cargado');

        // Espera que el DOM esté listo
        $(document).ready(function () {
            const zipInputSelector = 'input[name="postcode"]';

            // Escucha cambios en el campo de código postal
            $(document).on('change', zipInputSelector, function () {
                console.log('📦 Código postal cambiado, recargando...');
                location.reload();
            });
        });
    };
});