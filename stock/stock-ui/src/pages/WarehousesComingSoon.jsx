import React from 'react';
import { HiOutlineBuildingOffice2 } from 'react-icons/hi2';
import './warehouses-coming-soon.css';

export default function WarehousesComingSoon() {
  return (
    <div className="wh-soon" role="status" aria-live="polite">
      <div className="wh-soon-stage" aria-hidden="true">
        <span className="wh-soon-pulse wh-soon-pulse--1" />
        <span className="wh-soon-pulse wh-soon-pulse--2" />
        <span className="wh-soon-pulse wh-soon-pulse--3" />
        <div className="wh-soon-icon-wrap">
          <HiOutlineBuildingOffice2 className="wh-soon-icon" />
        </div>
      </div>

      <h1 className="wh-soon-title">Coming soon</h1>
      <p className="wh-soon-text">Warehouses module is launching shortly.</p>

      <div className="wh-soon-dots" aria-hidden="true">
        <span />
        <span />
        <span />
      </div>
    </div>
  );
}
