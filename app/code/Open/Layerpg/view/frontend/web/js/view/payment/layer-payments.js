/*browser:true*/
/*global define*/
define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'layerpg',
                component: 'Open_Layerpg/js/view/payment/method-renderer/layer-method'
            }
        );
        /** Add view logic here if needed */
        return Component.extend({});
    }
);
