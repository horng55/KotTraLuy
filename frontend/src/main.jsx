import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import './App.css'
import './auth-background.css'
import './dashboard-refresh.css'
import './typography.css'
import App from './App.jsx'
import './components/dashboard.css'
import './components/sales.css'
import './components/settings.css'
import './components/financial-pages.css'
import './components/integration-pages.css'
import './theme.css'
import './components/notifications.css'
import './components/workspace-forms.css'
import './components/financial-reports.css'

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
