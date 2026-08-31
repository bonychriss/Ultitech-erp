import { CFG } from './config.js'
import DeliveriesDashboardPage from './pages/DeliveriesDashboardPage.jsx'
import CreateDeliveryPage from './pages/CreateDeliveryPage.jsx'
import MyDeliveriesPage from './pages/MyDeliveriesPage.jsx'
import OrderDetailsPage from './pages/OrderDetailsPage.jsx'
import DeliveryNotesPage from './pages/DeliveryNotesPage.jsx'
import DeliveryNoteViewPage from './pages/DeliveryNoteViewPage.jsx'
import CreateDeliveryNotePage from './pages/CreateDeliveryNotePage.jsx'
import CustomerReviewsPage from './pages/CustomerReviewsPage.jsx'
import FinalPage from './pages/FinalPage.jsx'

export default function App() {
  const page = CFG.page || 'dashboard'
  if (page === 'create-delivery') return <CreateDeliveryPage />
  if (page === 'my-deliveries') return <MyDeliveriesPage />
  if (page === 'delivery-notes') return <DeliveryNotesPage />
  if (page === 'delivery-note-view') return <DeliveryNoteViewPage />
  if (page === 'create-delivery-note') return <CreateDeliveryNotePage />
  if (page === 'customer-reviews') return <CustomerReviewsPage />
  if (page === 'order-details') return <OrderDetailsPage />
  if (page === 'final') return <FinalPage />
  return <DeliveriesDashboardPage />
}
