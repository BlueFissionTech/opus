const Webpack = require("webpack");
const Path = require("path");
const fs = require("fs");
const TerserPlugin = require("terser-webpack-plugin");
const MiniCssExtractPlugin = require("mini-css-extract-plugin");
const CssMinimizerPlugin = require("css-minimizer-webpack-plugin");
const CopyWebpackPlugin = require("copy-webpack-plugin");
const FileManagerPlugin = require("filemanager-webpack-plugin");
const RemoveEmptyScriptsPlugin = require('webpack-remove-empty-scripts');

const activeTheme = getActiveThemeFromDatabase();

function getActiveThemeFromDatabase() {
  return 'default';
}

const opts = {
  rootDir: process.cwd(),
  devBuild: process.env.NODE_ENV !== "production"
};

// get a list of all possible addons
const addonsDir = Path.join(__dirname, "addons");

let addonsModules = {};
let addonsEntries = {};

if (fs.existsSync(addonsDir)) {
  const addonNames = fs.readdirSync(addonsDir);

  addonNames.forEach((addonName) => {
    const addonEntryPath1 = Path.join(addonsDir, addonName, `resource/src/module-${addonName}.js`);
    const addonEntryPath2 = Path.join(addonsDir, addonName, `resource/src/${addonName}.js`);

    if (fs.existsSync(addonEntryPath1)) {
      addonsModules[`module-${addonName}`] = addonEntryPath1;
    }
    if (fs.existsSync(addonEntryPath2)) {
      addonsEntries[`${addonName}`] = addonEntryPath2;
    }
  });
}

module.exports = {
  entry: {
    app: "./resource/src/js/app.js",
    // settings: "./resource/src/js/settings/index.js",
    // light: "./resource/src/scss/light.scss",
    // dark: "./resource/src/scss/dark.scss",
    main: "./resource/src/js/pages/admin/main.js",
    'login-page': "./resource/src/js/modules/app/login-page.js",
    'module-dashboard': "./resource/src/js/modules/app/module-dashboard.js",
    'module-dashboard': "./resource/src/js/modules/app/module-dashboard.js",
    'module-user': "./resource/src/js/modules/app/module-user.js",
    'module-addons': "./resource/src/js/modules/app/module-addons.js",
    'module-terminal': "./resource/src/js/modules/app/module-terminal.js",
    'module-content': "./resource/src/js/modules/app/module-content.js",
    'module-media': "./resource/src/js/modules/app/module-media.js",
    ... (activeTheme ? {[`theme-${activeTheme}`]: `./resource/markup/${activeTheme}/src/index.js`} : {}),
    ...addonsModules, // include the addon entries here
    ...addonsEntries // include the addon entries here
  },
  mode: process.env.NODE_ENV === "production" ? "production" : "development",
  devtool: process.env.NODE_ENV === "production" ? false : "inline-source-map",
  output: {
    filename: "js/[name].js",
    path: Path.join(opts.rootDir, "public/assets"),
    pathinfo: opts.devBuild
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
    // Remove empty js files from /dist
    new RemoveEmptyScriptsPlugin(),
    // Extract css files to seperate bundle
    new MiniCssExtractPlugin({
      filename: "css/[name].css",
      chunkFilename: "css/[id].css"
    }),
    // jQuery
    new Webpack.ProvidePlugin({
      $: "jquery",
      jQuery: "jquery",
      'window.jQuery': 'jquery',
      app: ["app", 'default'],
      Popper: ['popper.js', 'default']
    }),
    // Copy fonts and images to dist
    new CopyWebpackPlugin({
      patterns: [
        { from: "resource/src/img", to: "img" },
        {
          from: 'node_modules/xterm/css/xterm.css',
          to: 'css/xterm.css',
        },
        {
          from: 'resource/src/css/custom.css',
          to: 'css/custom.css',
        },
        {
          from: 'resource/src/css/admin.css',
          to: 'css/admin.css',
        },
        ...(activeTheme ? [{ from: `./resource/markup/${activeTheme}/assets`, to: '.' }] : [])
      ]
    }),
    // Copy dist folder to docs/dist
    // new FileManagerPlugin({
    //   events: {
    //     onEnd: {
    //       copy: [
    //         { source: "./dist/", destination: "./docs" }
    //       ]
    //     }
    //   }
    // }),
    // Ignore momentjs locales
    new Webpack.IgnorePlugin({
      resourceRegExp: /^\.\/locale$/,
      contextRegExp: /moment$/
    })
  ],
  module: {
    rules: [
      // Babel-loader
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
      // Css-loader & sass-loader
      {
        test: /\.(sa|sc|c)ss$/,
        use: [
          MiniCssExtractPlugin.loader,
          "css-loader",
          "postcss-loader",
          "sass-loader"
        ]
      },
      // Load fonts
      {
        test: /\.(woff(2)?|ttf|eot|svg)(\?v=\d+\.\d+\.\d+)?$/,
        type: "asset/resource",
        generator: {
          filename: "fonts/[name][ext]"
        }
      },
      // Load images
      {
        test: /\.(png|jpg|jpeg|gif)(\?v=\d+\.\d+\.\d+)?$/,
        type: "asset/resource",
        generator: {
          filename: "img/[name][ext]"
        }
      },
      // Expose loader
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
  },
  devServer: {
    static: {
      directory: Path.join(__dirname, "docs"),
    },
    port: 8080,
    open: true
  }
};
