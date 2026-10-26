import Layout1 from './Layout1.jsx'
import Layout2 from './Layout2.jsx'
import Layout3 from './Layout3.jsx'
import Layout4 from './Layout4.jsx'
import Layout5 from './Layout5.jsx'

/** Invoice layout library — add new layouts here. */
export const layouts = [
  {
    id: '1',
    name: 'Purple Classic',
    blurb: 'Bold purple header table, TOTAL DUE bar, three-column footer.',
    Component: Layout1,
  },
  {
    id: '2',
    name: 'Purple Brand Name',
    blurb: 'Purple pills, rounded TOTAL bar, and a black-and-purple footer wave.',
    Component: Layout2,
  },
  {
    id: '3',
    name: 'Navy & Orange Swoosh',
    blurb: 'Pre-made frame artwork with corner swooshes; details overlaid on top.',
    Component: Layout3,
  },
  {
    id: '4',
    name: 'Pixel Mart Red & Black',
    blurb: 'Vector frame with black title band and red angles; full overlaid invoice.',
    Component: Layout4,
  },
  {
    id: '5',
    name: 'Yellow Bar Classic',
    blurb: 'Original yellow-bar artwork with INVOICE, dark table header, and yellow total.',
    Component: Layout5,
  },
]

export function getLayout(id) {
  return layouts.find((l) => l.id === id) ?? layouts[0]
}
