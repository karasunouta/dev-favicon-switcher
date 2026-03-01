const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );

const plugins = defaultConfig.plugins.map( ( plugin ) => {
	if ( plugin.constructor.name === 'DependencyExtractionWebpackPlugin' ) {
		return new DependencyExtractionWebpackPlugin( {
			requestToExternal( request ) {
				if ( request === 'media-views' ) {
					return 'wp.media';
				}
				if ( request === 'customize-controls' ) {
					return 'wp.customize';
				}
			},
			requestToHandle( request ) {
				if ( request === 'media-views' ) {
					return 'media-views';
				}
				if ( request === 'customize-controls' ) {
					return 'customize-controls';
				}
			},
		} );
	}
	return plugin;
} );

module.exports = {
	...defaultConfig,
	plugins,
};
