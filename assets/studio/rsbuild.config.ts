import { defineConfig } from '@rsbuild/core'
import { pluginReact } from '@rsbuild/plugin-react'
import { pluginModuleFederation } from '@module-federation/rsbuild-plugin'
import { pluginGenerateEntrypoints } from '@pimcore/studio-ui-bundle/rsbuild/plugins'
import { createDynamicRemote } from '@pimcore/studio-ui-bundle/rsbuild/utils'
import { getBuildGroupId } from '@pimcore/studio-ui-bundle/bundler/build-id'
import path from 'path'
import fs from 'fs'
import packages from './package.json'

// Pimcore Studio plugin of the bundle (Module Federation remote). The output goes to
// src/Resources/public/studio/build/<buildId>, which is committed: a bundle is not built on deploy. assets:install
// serves it under /bundles/simplerestadapter/, Webpack\WebpackEntryPointProvider registers it with Studio.

// Content-derived id: building unchanged sources gives the same directory, so the committed build only changes with
// the sources
const buildId = getBuildGroupId(__dirname)
const buildRoot = path.resolve(__dirname, '..', '..', 'src', 'Resources', 'public', 'studio', 'build')
const buildPath = path.resolve(buildRoot, buildId)

// Drop stale build dirs: only the current build must be picked up by the WebpackEntryPointProvider.
if (fs.existsSync(buildRoot)) {
  fs.readdirSync(buildRoot, { withFileTypes: true }).forEach((entry) => {
    if (entry.isDirectory() && entry.name !== buildId) {
      fs.rmSync(path.resolve(buildRoot, entry.name), { recursive: true, force: true })
    }
  })
}

if (!fs.existsSync(buildPath)) {
  fs.mkdirSync(buildPath, { recursive: true })
}

const env: 'development' | 'production' = process.env.NODE_ENV === 'development' ? 'development' : 'production'

export default defineConfig({
  mode: env,
  source: {
    entry: {
      main: './js/src/main.ts'
    },
    decorators: {
      version: 'legacy'
    }
  },
  output: {
    manifest: true,
    assetPrefix: '/bundles/simplerestadapter/studio/build/' + buildId,
    distPath: {
      root: buildPath
    }
  },
  tools: {
    bundlerChain: (chain) => {
      chain.output.uniqueName('ci_hub_studio_ui')
    }
  },
  plugins: [
    pluginGenerateEntrypoints(),
    pluginReact(),
    pluginModuleFederation({
      name: 'ci_hub_studio_ui',
      filename: 'static/js/remoteEntry.js',
      exposes: {
        '.': './js/src/plugins.ts'
      },
      dts: false,
      remotes: {
        '@pimcore/studio-ui-bundle': createDynamicRemote('pimcore_studio_ui_bundle')
      },
      shared: {
        ...packages.dependencies,
        react: {
          singleton: true,
          eager: true,
          requiredVersion: false
        },
        'react-dom': {
          singleton: true,
          eager: true,
          requiredVersion: false
        }
      }
    })
  ]
})
