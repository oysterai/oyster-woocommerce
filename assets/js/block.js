/**
 * Editor UI for the dynamic "Oyster Skin Scan" block.
 *
 * No JSX / build step: uses wp.element.createElement directly so the plugin
 * ships without a bundler. Rendering on the storefront is done server-side by
 * Widget_Loader::render_block(), so save() returns null (dynamic block).
 */
(function (blocks, element, blockEditor, components, i18n) {
  var el = element.createElement
  var __ = i18n.__

  blocks.registerBlockType('oyster/skin-scan', {
    edit: function (props) {
      var height = props.attributes.height || 0
      var blockProps = blockEditor.useBlockProps()

      var inspector = el(
        blockEditor.InspectorControls,
        null,
        el(
          components.PanelBody,
          { title: __('Scan settings', 'oyster-woocommerce'), initialOpen: true },
          el(components.RangeControl, {
            label: __('Height (px, 0 = auto)', 'oyster-woocommerce'),
            value: height,
            min: 0,
            max: 1200,
            step: 20,
            onChange: function (value) {
              props.setAttributes({ height: value || 0 })
            },
          })
        )
      )

      var placeholder = el(
        components.Placeholder,
        {
          icon: 'visibility',
          label: __('Oyster Skin Scan', 'oyster-woocommerce'),
          instructions: __(
            'An inline skin-scan surface renders here on the storefront. Configure branding under Oyster → Widget.',
            'oyster-woocommerce'
          ),
        },
        height ? el('p', null, __('Height: ', 'oyster-woocommerce') + height + 'px') : null
      )

      return el('div', blockProps, [inspector, placeholder])
    },

    // Dynamic block — server renders the markup.
    save: function () {
      return null
    },
  })
})(
  window.wp.blocks,
  window.wp.element,
  window.wp.blockEditor,
  window.wp.components,
  window.wp.i18n
)
