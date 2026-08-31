import { Component } from 'react';

export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { hasError: false, error: null };
  }

  static getDerivedStateFromError(error) {
    return { hasError: true, error };
  }

  render() {
    if (this.state.hasError) {
      return (
        <div className="qt-shell">
          <div className="qt-alert qt-alert--error">
            {this.state.error?.toString() || 'Something went wrong.'}
          </div>
        </div>
      );
    }
    return this.props.children;
  }
}
