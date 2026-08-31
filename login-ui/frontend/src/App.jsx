import LoginPage from './pages/LoginPage.jsx'
import RegisterPage from './pages/RegisterPage.jsx'

export default function App() {
  if (window.__REGISTER_CFG__) {
    return <RegisterPage />
  }
  return <LoginPage />
}
