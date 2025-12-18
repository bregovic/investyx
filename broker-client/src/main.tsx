
import React from 'react'
import ReactDOM from 'react-dom/client'
import { FluentProvider, webLightTheme } from '@fluentui/react-components'
import App from './App'
import { TranslationProvider } from './context/TranslationContext'
import './index.css'

ReactDOM.createRoot(document.getElementById('root') as HTMLElement).render(
  <React.StrictMode>
    <FluentProvider theme={webLightTheme}>
      <TranslationProvider>
        <App />
      </TranslationProvider>
    </FluentProvider>
  </React.StrictMode>,
)
