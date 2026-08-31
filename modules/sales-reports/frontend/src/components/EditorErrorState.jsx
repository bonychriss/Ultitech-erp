import { DotLottieReact } from '@lottiefiles/dotlottie-react'
import { CFG } from '../config.js'

const DEFAULT_ERROR_LOTTIE = '/assets/animations/404%20Error%20page%20not%20found.lottie'

export default function EditorErrorState({
  title = 'The report editor failed to load.',
  message = '',
  action,
}) {
  const lottieUrl = CFG.urls?.errorLottie || DEFAULT_ERROR_LOTTIE

  return (
    <div className="word-app-loading">
      <div className="word-app-loading-inner word-app-error-inner">
        <div className="word-error-lottie" aria-hidden="true">
          <DotLottieReact src={lottieUrl} loop autoplay />
        </div>
        <p className="word-app-error-title">{title}</p>
        {message ? <p className="word-app-loading-hint">{message}</p> : null}
        {action}
      </div>
    </div>
  )
}
