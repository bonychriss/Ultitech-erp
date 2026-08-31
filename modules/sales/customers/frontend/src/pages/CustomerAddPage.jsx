import { useEffect, useState } from 'react';
import CustomerAddModal from '../components/CustomerAddModal.jsx';
import CustomerIndexPage from './CustomerIndexPage.jsx';
import { fetchAddInit } from '../api/catalogueDesk';

export default function CustomerAddPage() {
  const [indexUrl, setIndexUrl] = useState('');

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    fetchAddInit(params)
      .then((payload) => {
        setIndexUrl(payload?.urls?.index || '');
      })
      .catch(() => {
        setIndexUrl('');
      });
  }, []);

  function handleClose() {
    if (indexUrl) {
      window.location.href = indexUrl;
      return;
    }
    window.history.back();
  }

  function handleSuccess(result) {
    if (result?.redirect_url) {
      window.location.href = result.redirect_url;
    } else {
      handleClose();
    }
  }

  return (
    <>
      <CustomerIndexPage />
      <CustomerAddModal
        open
        idPrefix="ca-page"
        onClose={handleClose}
        onSuccess={handleSuccess}
      />
    </>
  );
}
