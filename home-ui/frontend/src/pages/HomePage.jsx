import { useEffect, useState } from 'react'
import logoIcon from '../assets/ui/dashboard.png'

function getCfg() {
  return window.__HOME_CFG__ || {}
}

function imgUrl(path) {
  const cfg = getCfg()
  const base = String(cfg.imgBase || './skilline/img/').replace(/\/?$/, '/')
  return `${base}${String(path || '').replace(/^\//, '')}`
}

const FEATURE_CARDS = [
  {
    title: 'Sales, invoicing & collections',
    text: 'Quotes, orders, and invoices in one flow so cash in stays tied to the books.',
    color: '#5B72EE',
  },
  {
    title: 'Finance, expenses & cash',
    text: 'Balances, expenses, VAT, and cash books that match what your teams actually post.',
    color: '#F48C06',
  },
  {
    title: 'Stock, HR & operations',
    text: 'Inventory, payroll, attendance, and delivery stay connected to the same company data.',
    color: '#29B9E7',
  },
]

const WHY_POINTS = [
  'One platform for every department - no extra app per team.',
  'Role-based access and company isolation keep tenant data separate.',
  'Start with what you need, then grow into the rest of the suite.',
]

export default function HomePage() {
  const cfg = getCfg()
  const loginUrl = cfg.loginUrl || 'login.php'
  const trialUrl = cfg.trialUrl || 'free-trial.php'
  const year = cfg.year || new Date().getFullYear()
  const [navOpen, setNavOpen] = useState(false)

  useEffect(() => {
    let cancelled = false
    ;(async () => {
      try {
        if (!window.AOS) {
          await new Promise((resolve, reject) => {
            const s = document.createElement('script')
            s.src = 'https://unpkg.com/aos@next/dist/aos.js'
            s.onload = resolve
            s.onerror = reject
            document.body.appendChild(s)
          })
        }
        if (!cancelled && window.AOS) {
          window.AOS.init({ once: true, duration: 700, easing: 'ease-out-cubic' })
        }
      } catch {
        // Animation library is optional.
      }
    })()
    return () => {
      cancelled = true
    }
  }, [])

  return (
    <div className="sk-page antialiased text-gray-700">
      <div className="w-full bg-cream">
        <div className="flex flex-col max-w-screen-xl px-8 mx-auto md:items-center md:justify-between md:flex-row">
          <div className="flex flex-row items-center justify-between py-6">
            <div className="relative md:mt-8">
              <a
                href={cfg.homeUrl || './'}
                className="text-lg relative z-50 font-bold tracking-wide text-gray-900 rounded-lg focus:outline-none inline-flex items-center gap-2"
              >
                <img src={logoIcon} alt="" className="w-7 h-7" aria-hidden="true" />
                UltiTech
              </a>
              <svg className="h-11 z-40 absolute -top-2 -left-3" viewBox="0 0 79 79" fill="none" aria-hidden="true">
                <path
                  d="M35.2574 2.24264C37.6005 -0.100501 41.3995 -0.100505 43.7426 2.24264L76.7574 35.2574C79.1005 37.6005 79.1005 41.3995 76.7574 43.7426L43.7426 76.7574C41.3995 79.1005 37.6005 79.1005 35.2574 76.7574L2.24264 43.7426C-0.100501 41.3995 -0.100505 37.6005 2.24264 35.2574L35.2574 2.24264Z"
                  fill="#65DAFF"
                />
              </svg>
            </div>
            <button
              type="button"
              className="rounded-lg md:hidden focus:outline-none"
              aria-label="Menu"
              onClick={() => setNavOpen((o) => !o)}
            >
              <svg fill="currentColor" viewBox="0 0 20 20" className="w-6 h-6">
                {navOpen ? (
                  <path
                    fillRule="evenodd"
                    d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                    clipRule="evenodd"
                  />
                ) : (
                  <path
                    fillRule="evenodd"
                    d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM9 15a1 1 0 011-1h6a1 1 0 110 2h-6a1 1 0 01-1-1z"
                    clipRule="evenodd"
                  />
                )}
              </svg>
            </button>
          </div>
          <nav
            className={`h-0 md:h-auto flex flex-col flex-grow md:items-center pb-4 md:pb-0 md:flex md:justify-end md:flex-row origin-top duration-300 ${
              navOpen ? 'h-full scale-y-100' : 'scale-y-0 md:scale-y-100'
            }`}
          >
            <a className="px-4 py-2 mt-2 text-sm md:mt-8 md:ml-4 hover:text-gray-900" href="#home">
              Home
            </a>
            <a className="px-4 py-2 mt-2 text-sm md:mt-8 md:ml-4 hover:text-gray-900" href="#modules">
              Modules
            </a>
            <a className="px-4 py-2 mt-2 text-sm md:mt-8 md:ml-4 hover:text-gray-900" href="#why">
              Why UltiTech
            </a>
            <a className="px-4 py-2 mt-2 text-sm md:mt-8 md:ml-4 hover:text-gray-900" href="#pricing">
              Pricing
            </a>
            <a
              className="px-10 py-3 mt-2 text-sm text-center bg-white text-gray-800 rounded-full md:mt-8 md:ml-4"
              href={loginUrl}
            >
              Login
            </a>
            <a
              className="px-10 py-3 mt-2 text-sm text-center bg-yellow-500 text-white rounded-full md:mt-8 md:ml-4"
              href={trialUrl}
            >
              Free trial
            </a>
          </nav>
        </div>
      </div>

      <div id="home" className="bg-cream">
        <div className="max-w-screen-xl px-8 mx-auto flex flex-col lg:flex-row items-start">
          <div className="flex flex-col w-full lg:w-6/12 justify-center lg:pt-24 items-start text-center lg:text-left mb-5 md:mb-0">
            <h1 data-aos="fade-right" className="my-4 text-5xl font-bold leading-tight text-darken">
              <span className="text-yellow-500">Running</span> your business is now much easier
            </h1>
            <p data-aos="fade-down" data-aos-delay="300" className="leading-normal text-2xl mb-8">
              UltiTech ERP brings finance, sales, stock, HR, and operations together so every team
              works from the same live numbers.
            </p>
            <div
              data-aos="fade-up"
              data-aos-delay="700"
              className="w-full md:flex items-center justify-center lg:justify-start md:space-x-5"
            >
              <a
                href={trialUrl}
                className="inline-block lg:mx-0 bg-yellow-500 text-white text-xl font-bold rounded-full py-4 px-9 focus:outline-none transform transition hover:scale-110 duration-300 ease-in-out"
              >
                Start free trial
              </a>
              <a
                href={loginUrl}
                className="flex items-center justify-center space-x-3 mt-5 md:mt-0 focus:outline-none transform transition hover:scale-110 duration-300 ease-in-out"
              >
                <span className="bg-white w-14 h-14 rounded-full flex items-center justify-center shadow">
                  <svg className="w-5 h-5 ml-1" viewBox="0 0 24 28" fill="none" aria-hidden="true">
                    <path
                      d="M22.5751 12.8097C23.2212 13.1983 23.2212 14.135 22.5751 14.5236L1.51538 27.1891C0.848878 27.5899 5.91205e-07 27.1099 6.25202e-07 26.3321L1.73245e-06 1.00123C1.76645e-06 0.223477 0.848877 -0.256572 1.51538 0.14427L22.5751 12.8097Z"
                      fill="#23BDEE"
                    />
                  </svg>
                </span>
                <span>Sign in to your company</span>
              </a>
            </div>
          </div>

          <div className="w-full lg:w-6/12 lg:-mt-10 relative">
            <img
              data-aos="fade-up"
              className="w-10/12 mx-auto 2xl:-mb-20"
              src={imgUrl('girl.png')}
              alt="UltiTech ERP"
            />
            <div
              data-aos="fade-up"
              data-aos-delay="300"
              className="absolute top-20 -left-6 sm:top-32 sm:left-10 md:top-40 md:left-16 lg:-left-0 lg:top-52 floating-4"
            >
              <img className="bg-white bg-opacity-80 rounded-lg h-12 sm:h-16" src={imgUrl('calendar.svg')} alt="" />
            </div>
            <div
              data-aos="fade-up"
              data-aos-delay="500"
              className="absolute bottom-14 -left-4 sm:left-2 sm:bottom-20 lg:bottom-24 lg:-left-4 floating"
            >
              <img className="bg-white bg-opacity-80 rounded-lg h-20 sm:h-28" src={imgUrl('ux-class.svg')} alt="" />
            </div>
            <div
              data-aos="fade-up"
              data-aos-delay="600"
              className="absolute bottom-20 md:bottom-48 lg:bottom-52 -right-6 lg:right-8 floating-4"
            >
              <img className="bg-white bg-opacity-80 rounded-lg h-12 sm:h-16" src={imgUrl('congrat.svg')} alt="" />
            </div>
          </div>
        </div>

        <div className="text-white -mt-14 sm:-mt-24 lg:-mt-36 z-40 relative">
          <svg className="xl:h-40 xl:w-full" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 120" preserveAspectRatio="none">
            <path
              d="M600,112.77C268.63,112.77,0,65.52,0,7.23V120H1200V7.23C1200,65.52,931.37,112.77,600,112.77Z"
              fill="currentColor"
            />
          </svg>
          <div className="bg-white w-full h-20 -mt-px" />
        </div>
      </div>

      <div className="container px-4 lg:px-8 mx-auto max-w-screen-xl overflow-x-hidden">
        <div className="max-w-4xl mx-auto">
          <h2 className="text-center mb-3 text-gray-400 font-medium">Trusted by growing companies</h2>
          <div className="grid grid-cols-3 lg:grid-cols-6 gap-4 justify-items-center opacity-70">
            {['google', 'netflix', 'airbnb', 'amazon', 'facebook', 'grab'].map((name) => (
              <img key={name} className="h-7" src={imgUrl(`company/${name}.svg`)} alt="" />
            ))}
          </div>
        </div>

        <div id="modules" data-aos="flip-up" className="max-w-xl mx-auto text-center mt-24">
          <h2 className="font-bold text-darken my-3 text-2xl">
            All-In-One <span className="text-yellow-500">Cloud ERP.</span>
          </h2>
          <p className="leading-relaxed text-gray-500">
            One powerful suite that combines the tools needed to run finance, sales, stock, and people
            without juggling spreadsheets.
          </p>
        </div>

        <div className="grid md:grid-cols-3 gap-14 md:gap-5 mt-20">
          {FEATURE_CARDS.map((card, index) => (
            <div
              key={card.title}
              data-aos="fade-up"
              data-aos-delay={index * 150}
              className="bg-white shadow-xl p-6 text-center rounded-xl"
            >
              <div
                style={{ background: card.color }}
                className="rounded-full w-16 h-16 flex items-center justify-center mx-auto shadow-lg transform -translate-y-12 text-white text-2xl font-bold"
              >
                {index + 1}
              </div>
              <h3 className="font-medium text-xl mb-3 lg:px-8 text-darken">{card.title}</h3>
              <p className="px-4 text-gray-500">{card.text}</p>
            </div>
          ))}
        </div>

        <div id="why" className="mt-28">
          <div data-aos="flip-down" className="text-center max-w-screen-md mx-auto">
            <h2 className="text-3xl font-bold mb-4">
              What is <span className="text-yellow-500">UltiTech?</span>
            </h2>
            <p className="text-gray-500">
              UltiTech ERP is a multi-company platform for recording sales, expenses, cash movements,
              stock, payroll, and reports - so owners and teams see the same truth in real time.
            </p>
          </div>
          <div data-aos="fade-up" className="flex flex-col md:flex-row justify-center space-y-5 md:space-y-0 md:space-x-6 lg:space-x-10 mt-7">
            <div className="relative md:w-5/12">
              <img className="rounded-2xl w-full" src={imgUrl('Rectangle 19.png')} alt="" />
              <div className="absolute bg-black bg-opacity-20 inset-0 rounded-2xl flex items-center justify-center">
                <div className="text-center px-6">
                  <h3 className="uppercase text-white font-bold text-sm lg:text-xl mb-3">For owners</h3>
                  <a
                    href={trialUrl}
                    className="inline-block rounded-full text-white border text-xs lg:text-base px-6 py-3 font-medium transform transition hover:scale-110 duration-300"
                  >
                    Start today
                  </a>
                </div>
              </div>
            </div>
            <div className="relative md:w-5/12">
              <img className="rounded-2xl w-full" src={imgUrl('Rectangle 21.png')} alt="" />
              <div className="absolute bg-black bg-opacity-20 inset-0 rounded-2xl flex items-center justify-center">
                <div className="text-center px-6">
                  <h3 className="uppercase text-white font-bold text-sm lg:text-xl mb-3">For teams</h3>
                  <a
                    href={loginUrl}
                    className="inline-block rounded-full text-white text-xs lg:text-base px-6 py-3 font-medium transform transition hover:scale-110 duration-300"
                    style={{ background: 'rgba(35, 189, 238, 0.9)' }}
                  >
                    Sign in
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div className="sm:flex items-center sm:space-x-8 mt-36">
          <div data-aos="fade-right" className="sm:w-1/2 relative">
            <div className="bg-yellow-500 rounded-full absolute w-12 h-12 z-0 -left-4 -top-3 animate-pulse" />
            <h2 className="font-semibold text-2xl relative z-50 text-darken lg:pr-10">
              Everything you used to do in separate tools,{' '}
              <span className="text-yellow-500">you can do in UltiTech</span>
            </h2>
            <p className="py-5 lg:pr-32 text-gray-500">
              Manage invoices, expenses, cash books, stock, and payroll in one secure cloud workspace -
              with reports that stay in sync with day-to-day posting.
            </p>
            <ul className="space-y-3 text-gray-600">
              {WHY_POINTS.map((point) => (
                <li key={point} className="flex gap-3">
                  <span className="text-yellow-500 font-bold">•</span>
                  <span>{point}</span>
                </li>
              ))}
            </ul>
          </div>
          <div data-aos="fade-left" className="sm:w-1/2 relative mt-10 sm:mt-0">
            <div style={{ background: '#23BDEE' }} className="floating w-24 h-24 absolute rounded-lg z-0 -top-3 -left-3" />
            <img className="rounded-xl z-40 relative w-full" src={imgUrl('teacher-explaining.png')} alt="" />
            <div className="bg-yellow-500 w-40 h-40 floating absolute rounded-lg z-10 -bottom-3 -right-3" />
          </div>
        </div>

        <div id="pricing" data-aos="zoom-in" className="mt-28 mb-10 text-center max-w-3xl mx-auto">
          <h2 className="text-darken text-2xl font-semibold">
            All modules. <span className="text-yellow-500">One free trial.</span>
          </h2>
          <p className="text-gray-500 my-5">
            Use the full platform for 14 days. No card up front - finance, sales, stock, HR, and
            operations are included.
          </p>
          <a
            href={trialUrl}
            className="inline-block px-8 py-4 bg-yellow-500 text-white font-semibold rounded-full transform transition hover:scale-110 duration-300"
          >
            Start now - it&apos;s free
          </a>
        </div>
      </div>

      <footer className="bg-cream mt-10">
        <div className="max-w-screen-xl mx-auto px-8 py-12 flex flex-col md:flex-row md:justify-between gap-8">
          <div>
            <a href={cfg.homeUrl || './'} className="font-bold text-darken text-lg inline-flex items-center gap-2">
              <img src={logoIcon} alt="" className="w-6 h-6" aria-hidden="true" />
              UltiTech ERP
            </a>
            <p className="text-gray-500 mt-3 max-w-sm">
              One platform for finance, sales, stock, people, and delivery.
            </p>
          </div>
          <div className="flex gap-12 text-sm">
            <div className="flex flex-col gap-2">
              <strong className="text-darken">Product</strong>
              <a href="#modules">Modules</a>
              <a href="#why">Why UltiTech</a>
              <a href="#pricing">Pricing</a>
            </div>
            <div className="flex flex-col gap-2">
              <strong className="text-darken">Account</strong>
              <a href={loginUrl}>Login</a>
              <a href={trialUrl}>Free trial</a>
            </div>
          </div>
        </div>
        <p className="text-center text-sm text-gray-500 pb-8">&copy; {year} Ultimate General Trading</p>
      </footer>
    </div>
  )
}
