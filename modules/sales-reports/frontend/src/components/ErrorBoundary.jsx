import { Component } from 'react'

import EditorErrorState from './EditorErrorState.jsx'

export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props)
    this.state = { error: null }
  }

  static getDerivedStateFromError(error) {
    return { error }
  }

  componentDidCatch(error, info) {
    console.error('Sales Reports UI error:', error, info)
  }

  render() {
    if (this.state.error) {
      return (
        <EditorErrorState
          message={this.state.error.message || String(this.state.error)}
          action={(
            <button type="button" className="btn btn-primary btn-sm mt-3" onClick={() => window.location.reload()}>
              Reload page
            </button>
          )}
        />
      )
    }
    return this.props.children
  }
}
