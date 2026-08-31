import { Component } from 'react';

export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { error: null };
  }

  static getDerivedStateFromError(error) {
    return { error };
  }

  render() {
    if (this.state.error) {
      return (
        <div className="exp-desk-flash exp-desk-flash-error" role="alert">
          {this.state.error?.message || 'Something went wrong.'}
        </div>
      );
    }
    return this.props.children;
  }
}
