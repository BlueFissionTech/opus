const Webpack = require("webpack");
const Path = require("path");
const TerserPlugin = require("terser-webpack-plugin");
const MiniCssExtractPlugin = require("mini-css-extract-plugin");
const CssMinimizerPlugin = require("css-minimizer-webpack-plugin");
const CopyWebpackPlugin = require("copy-webpack-plugin");
const RemoveEmptyScriptsPlugin = require('webpack-remove-empty-scripts');
const { createAssetManifest } = require('./tools/asset-pipeline.cjs');

const manifest = createAssetManifest(__dirname, process.env);
const devBuild = process.env.NODE_ENV !== "production";

module.exports = {
  entry: manifest.entries,
  mode: process.env.NODE_ENV === "production" ? "production" : "development",
  devtool: process.env.NODE_ENV === "production" ? false : "inline-source-map",
  output: {
    filename: "js/[name].js",
    path: manifest.outputRoot,
    pathinfo: devBuild,
    publicPath: "/assets/",
    clean: true
  },
  performance: { hints: false },
  optimization: {
    minimizer: [
      new TerserPlugin({
        parallel: true,
        terserOptions: {
          ecma: 5
        }
      }),
      new CssMinimizerPlugin({})
    ]
  },
  plugins: [
    // Remove JavaScript files emitted for style-only entries.
    new RemoveEmptyScriptsPlugin(),
    // Extract styles into independently cacheable bundles.
    new MiniCssExtractPlugin({
      filename: "css/[name].css",
      chunkFilename: "css/[id].css"
    }),
    // Preserve globals required by the remaining legacy modules.
    new Webpack.ProvidePlugin({
      $: "jquery",
      jQuery: "jquery",
      'window.jQuery': 'jquery',
      app: ["app", 'default'],
      Popper: ['popper.js', 'default']
    }),
    // Publish validated static sources from the asset manifest.
    new CopyWebpackPlugin({
      patterns: manifest.copyPatterns
    }),
    // Exclude unused Moment.js locales from application bundles.
    new Webpack.IgnorePlugin({
      resourceRegExp: /^\.\/locale$/,
      contextRegExp: /moment$/
    })
  ],
  module: {
    rules: [
      // Compile authored JavaScript while leaving package code intact.
      {
        test: /\.js$/,
        exclude: /(node_modules)/,
        use: {
          loader: "babel-loader",
          options: {
            cacheDirectory: true
          }
        }
      },
      // Compile authored CSS and Sass into extracted bundles.
      {
        test: /\.(sa|sc|c)ss$/,
        use: [
          MiniCssExtractPlugin.loader,
          "css-loader",
          "postcss-loader",
          "sass-loader"
        ]
      },
      // Publish fonts referenced by compiled styles.
      {
        test: /\.(woff(2)?|ttf|eot|svg)(\?v=\d+\.\d+\.\d+)?$/,
        type: "asset/resource",
        generator: {
          filename: "fonts/[name][ext]"
        }
      },
      // Publish images referenced by compiled modules.
      {
        test: /\.(png|jpg|jpeg|gif)(\?v=\d+\.\d+\.\d+)?$/,
        type: "asset/resource",
        generator: {
          filename: "img/[name][ext]"
        }
      },
      // Preserve globals required by the remaining legacy modules.
      {
        test: require.resolve("jquery"),
        loader: "expose-loader",
        options: {
          exposes: [
            {
              globalName: "$",
              override: true,
            },
            {
              globalName: "jQuery",
              override: true,
            },
          ],
        },
      }
    ]
  },
  resolve: {
    extensions: [".js", ".scss"],
    modules: ["node_modules"],
    alias: {
      request$: "xhr",
      app: Path.resolve(__dirname, './resource/src/js/app')
    }
  }
};
