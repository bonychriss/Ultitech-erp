import React from 'react';
import Inbox from './pages/Inbox';

export default function App({ page = 'inbox', data = {} }) {
  switch (page) {
    case 'inbox':
    default:
      return <Inbox data={data} />;
  }
}
