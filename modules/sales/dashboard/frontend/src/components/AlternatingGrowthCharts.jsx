import { useCallback, useEffect, useRef, useState } from 'react';
import GrowthChart from './GrowthChart.jsx';

const SLIDES = [
  {
    key: 'revenue',
    title: 'Revenue Growth',
    tooltipLabel: 'Total Revenue :',
    theme: 'revenue',
    gradientId: 'revenue-area-gradient',
    ariaLabel: 'Revenue growth chart',
    seriesKey: 'revenue',
  },
  {
    key: 'quote',
    title: 'Quotation Growth',
    tooltipLabel: 'Total Quoted :',
    theme: 'quote',
    gradientId: 'quote-area-gradient',
    ariaLabel: 'Quotation growth chart',
    seriesKey: 'quote',
  },
];

const ROTATE_MS = 5000;
const FADE_MS = 350;
const RANGE_ORDER = ['day', 'weekly', 'monthly'];

export default function AlternatingGrowthCharts({ revenueSeries = {}, quoteSeries = {} }) {
  const [activeIndex, setActiveIndex] = useState(0);
  const [phase, setPhase] = useState('visible');
  const [range, setRange] = useState('weekly');
  const [paused, setPaused] = useState(false);
  const activeIndexRef = useRef(0);
  const rangeRef = useRef('weekly');
  const transitioningRef = useRef(false);
  const timerRef = useRef(null);
  const kickoffRef = useRef(null);

  const seriesMap = { revenue: revenueSeries, quote: quoteSeries };

  const advanceRange = useCallback(() => {
    const currentIdx = RANGE_ORDER.indexOf(rangeRef.current);
    const nextIdx = currentIdx >= 0 ? (currentIdx + 1) % RANGE_ORDER.length : 0;
    rangeRef.current = RANGE_ORDER[nextIdx];
    setRange(RANGE_ORDER[nextIdx]);
  }, []);

  const goTo = useCallback((nextIndex) => {
    if (transitioningRef.current) return;
    if (nextIndex === activeIndexRef.current) return;

    transitioningRef.current = true;
    setPhase('exit');

    window.setTimeout(() => {
      activeIndexRef.current = nextIndex;
      setActiveIndex(nextIndex);
      setPhase('enter');

      window.setTimeout(() => {
        setPhase('visible');
        transitioningRef.current = false;
      }, 40);
    }, FADE_MS);
  }, []);

  const advance = useCallback(() => {
    advanceRange();
    const next = (activeIndexRef.current + 1) % SLIDES.length;
    goTo(next);
  }, [advanceRange, goTo]);

  const handleRangeChange = useCallback((nextRange) => {
    rangeRef.current = nextRange;
    setRange(nextRange);
  }, []);

  useEffect(() => {
    if (paused) {
      if (kickoffRef.current) window.clearTimeout(kickoffRef.current);
      if (timerRef.current) window.clearInterval(timerRef.current);
      kickoffRef.current = null;
      timerRef.current = null;
      return undefined;
    }

    kickoffRef.current = window.setTimeout(advance, ROTATE_MS);
    timerRef.current = window.setInterval(advance, ROTATE_MS);

    return () => {
      if (kickoffRef.current) window.clearTimeout(kickoffRef.current);
      if (timerRef.current) window.clearInterval(timerRef.current);
    };
  }, [advance, paused]);

  const slide = SLIDES[activeIndex];
  const slideClass = `chart-carousel__slide chart-carousel__slide--${phase}`;

  return (
    <div className="chart-carousel chart-carousel--autoplay" data-active={slide.key}>
      <div
        className="chart-carousel__stage"
        aria-live="polite"
        onMouseEnter={() => setPaused(true)}
        onMouseLeave={() => setPaused(false)}
      >
        <div key={`${slide.key}-${activeIndex}-${range}`} className={slideClass}>
          <GrowthChart
            title={slide.title}
            series={seriesMap[slide.seriesKey]}
            tooltipLabel={slide.tooltipLabel}
            range={range}
            onRangeChange={handleRangeChange}
            autoCycle
            theme={slide.theme}
            gradientId={slide.gradientId}
            ariaLabel={slide.ariaLabel}
          />
        </div>
      </div>

      <div className="chart-carousel__footer">
        <div className="chart-carousel__shifter" role="tablist" aria-label="Chart type">
          {SLIDES.map((item, index) => (
            <button
              key={item.key}
              type="button"
              role="tab"
              aria-selected={activeIndex === index}
              aria-label={item.title}
              className={`chart-carousel__shift-seg${activeIndex === index ? ' is-active' : ''}`}
              onClick={() => goTo(index)}
            />
          ))}
        </div>
      </div>
    </div>
  );
}
