import { Truck, Car, Wrench } from 'lucide-react'
import { CFG } from '../config.js'

const HUB_VIDEO =
  'https://d8j0ntlcm91z4.cloudfront.net/user_38xzZboKViGWJOttwIXH07lWA1P/hf_20260826_124724_bc041163-d651-425f-aea3-2acc1efc2c96.mp4'

function HubCard({ href, ariaLabel, icon, label, value, sub, tone }) {
  return (
    <a className="dlv-hub__card" href={href} role="listitem" aria-label={ariaLabel}>
      <span className="dlv-hub__card-glass" aria-hidden="true" />
      <span className="dlv-hub__card-shine" aria-hidden="true" />
      <span className="dlv-hub__card-rim" aria-hidden="true" />
      <span className={`dlv-hub__icon dlv-hub__icon--${tone}`} aria-hidden="true">
        {icon}
      </span>
      <span className="dlv-hub__label">{label}</span>
      <span className="dlv-hub__value">{value}</span>
      <span className="dlv-hub__sub">{sub}</span>
    </a>
  )
}

export default function DeliveriesHubPage() {
  const urls = CFG.data?.urls || {}
  const deliveryUrl = urls.dashboard || 'index.php?module=deliveries'
  const vehicleCareUrl = urls.driverKpiDelivery
    || '../driver-kpi/index?module=driver_kpi&service=delivery'
  const rideUrl = urls.driverKpiRide || '../driver-kpi/index?module=driver_kpi&service=ride'
  const modulesUrl = urls.modules || '../select-module.php'

  return (
    <div className="dlv-hub-stage">
      <video
        className="dlv-hub-stage__video"
        src={HUB_VIDEO}
        autoPlay
        muted
        loop
        playsInline
        aria-hidden="true"
      />
      <div className="dlv-hub-stage__veil" aria-hidden="true" />

      <div className="dlv-hub-page">
        <p className="dlv-hub__lede">
          Open the delivery desk, or record vehicle care and ride performance.
        </p>

        <div className="dlv-hub__cards" role="list">
          <HubCard
            href={deliveryUrl}
            ariaLabel="Open delivery desk"
            tone="blue"
            icon={<Truck size={18} strokeWidth={2} />}
            label="Delivery"
            value="Desk"
            sub="Trips, pending & POD tracking"
          />
          <HubCard
            href={vehicleCareUrl}
            ariaLabel="Record vehicle care for delivery"
            tone="amber"
            icon={<Wrench size={18} strokeWidth={2} />}
            label="Vehicle care"
            value="Record"
            sub="Maintenance & daily inspections"
          />
          <HubCard
            href={rideUrl}
            ariaLabel="Open ride service recordings"
            tone="green"
            icon={<Car size={18} strokeWidth={2} />}
            label="Ride service"
            value="Record"
            sub="Ride performance scores"
          />
        </div>

        <p className="dlv-hub__back">
          <a href={modulesUrl}>&larr; Back to modules</a>
        </p>
      </div>
    </div>
  )
}
