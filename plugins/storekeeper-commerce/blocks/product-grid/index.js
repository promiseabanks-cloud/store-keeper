/**
 * Editor UI for the product grid. Plain `wp.element` so the block ships without a
 * build step; the block itself is rendered on the server.
 */
(function (blocks, element, blockEditor, components, i18n) {
    var el = element.createElement;
    var __ = i18n.__;
    var useBlockProps = blockEditor.useBlockProps;
    var InspectorControls = blockEditor.InspectorControls;
    var PanelBody = components.PanelBody;
    var TextControl = components.TextControl;
    var RangeControl = components.RangeControl;

    blocks.registerBlockType('storekeeper/product-grid', {
        edit: function (props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;

            return el(
                'div',
                useBlockProps({ className: 'skf-block-preview' }),
                el(
                    InspectorControls,
                    null,
                    el(
                        PanelBody,
                        { title: __('Products', 'storekeeper'), initialOpen: true },
                        el(TextControl, {
                            label: __('Heading', 'storekeeper'),
                            value: attributes.heading,
                            onChange: function (value) {
                                setAttributes({ heading: value });
                            }
                        }),
                        el(TextControl, {
                            label: __('Category slug (blank for everything)', 'storekeeper'),
                            value: attributes.category,
                            onChange: function (value) {
                                setAttributes({ category: value });
                            }
                        }),
                        el(RangeControl, {
                            label: __('Products shown', 'storekeeper'),
                            value: attributes.limit,
                            min: 1,
                            max: 24,
                            onChange: function (value) {
                                setAttributes({ limit: value });
                            }
                        })
                    )
                ),
                el(
                    'div',
                    { className: 'skf-block-preview__inner' },
                    el('strong', null, attributes.heading || __('Product grid', 'storekeeper')),
                    el(
                        'p',
                        null,
                        attributes.limit + ' ' + __('products, live prices, rendered on the storefront.', 'storekeeper')
                    )
                )
            );
        },
        save: function () {
            return null;
        }
    });
})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n);
