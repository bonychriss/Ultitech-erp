import LoginPage from './pages/LoginPage.jsx'
import RegisterPage from './pages/RegisterPage.jsx'
import TrialRegisterPage from './pages/TrialRegisterPage.jsx'

export default function App() {
  if (window.__TRIAL_CFG__) {
    return <TrialRegisterPage />
  }
  if (window.__REGISTER_CFG__) {
    return <RegisterPage />
  }
  return <LoginPage />
}
