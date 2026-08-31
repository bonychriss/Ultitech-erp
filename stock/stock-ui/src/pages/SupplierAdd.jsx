import React from 'react';
import SupplierEdit from './SupplierEdit';

export default function SupplierAdd({ data }) {
  return <SupplierEdit data={{ ...data, mode: 'add' }} />;
}
