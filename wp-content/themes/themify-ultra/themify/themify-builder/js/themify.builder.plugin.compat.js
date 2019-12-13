(function ($) {

    'use strict';
    /*
    * Rank Math integration with Themify Builder                                                                                                               _                                                                                       _
    */
    function tb_rank_math_integration(){
        if('undefined' === typeof RankMathApp){
            return;
        }
        RankMathApp.registerPlugin( 'themify_builder' );
        wp.hooks.addFilter( 'rank_math_content', 'themify_builder', tb_callback_function_to_fetch_content );
        function tb_callback_function_to_fetch_content(content){
            return content + themifyBuilder.builder_output;
        }

        var ajaxReq;
        function reloadRankMath(){
            if ( ajaxReq !== undefined && 4 !== ajaxReq.readyState) {
                ajaxReq.abort();
            }
            ajaxReq = $.ajax({
                type: "POST",
                url: ajaxurl,
                data: {
                    action: 'tb_rank_math_content_ajax',
                    id: themifyBuilder.post_ID,
                    data: tb_app.Mixins.Builder.toJSON(tb_app.Instances.Builder[tb_app.builderIndex].el)
                },
                success: function (data) {
                    themifyBuilder.builder_output = data;
                    RankMathApp.reloadPlugin( 'themify_builder' );
                }
            });
        }
        tb_app['_hasChanged'] = false;
        Object.defineProperty(tb_app, 'hasChanged', {
            get: function() { return this._hasChanged; },
            set: function(v) {
                this._hasChanged = v;
                if(true === v ){
                    reloadRankMath();
                }
            }
        });
        tb_app['_saving'] = false;
        Object.defineProperty(tb_app, 'saving', {
            get: function() { return this._saving; },
            set: function(v) {
                this._saving = v;
                if(false === v ){
                    reloadRankMath();
                }
            }
        });
    }

    $(function() {
        tb_rank_math_integration();
    });

})(jQuery);
