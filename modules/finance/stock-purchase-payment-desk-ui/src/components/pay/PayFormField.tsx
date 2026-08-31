import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes } from 'react';

interface PayFormFieldProps {
  id: string;
  label: string;
  required?: boolean;
  children: ReactNode;
}

export function PayFormField({ id, label, required, children }: PayFormFieldProps) {
  return (
    <div className="sppd-pay-field">
      <label htmlFor={id}>
        {label}
        {required ? ' *' : ''}
      </label>
      {children}
    </div>
  );
}

export function PayTextInput(props: InputHTMLAttributes<HTMLInputElement>) {
  return <input {...props} className={`sppd-pay-input${props.className ? ` ${props.className}` : ''}`} />;
}

export function PaySelect(props: SelectHTMLAttributes<HTMLSelectElement>) {
  return <select {...props} className={`sppd-pay-input${props.className ? ` ${props.className}` : ''}`} />;
}
