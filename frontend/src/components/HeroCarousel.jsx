import { useEffect, useState } from 'react';
import { LeftOutlined, RightOutlined } from '@ant-design/icons';
import slideBc from '../assets/bc.png';
import slideTr from '../assets/tr.png';
import slideQp from '../assets/qp.png';
import slideCr from '../assets/cr.png';

const SLIDES = [slideBc, slideTr, slideQp, slideCr];
const CAPTIONS = [
  'We prioritize your health.',
  'Compassionate care, every time.',
  'Excellence in Healthcare.',
  'Your wellbeing is our mission.',
];
const SLIDE_INTERVAL_MS = 5000;

/**
 * Rotating background carousel — same images, ~5s interval, and fade
 * in/out as the original shared staff landing page (DashboardHome, now
 * removed) used. Relocated here as a full-viewport FIXED background
 * (see .hero-carousel* in index.css) rather than an in-flow page
 * section, since its only remaining use is behind TrackingPage's content
 * — the mechanism (image rotation, caption fade, prev/next) is otherwise
 * unchanged, not rebuilt.
 */
export default function HeroCarousel() {
  const [slideIndex, setSlideIndex] = useState(0);

  useEffect(() => {
    const id = setInterval(() => setSlideIndex((i) => (i + 1) % SLIDES.length), SLIDE_INTERVAL_MS);
    return () => clearInterval(id);
  }, []);

  const goPrev = () => setSlideIndex((i) => (i - 1 + SLIDES.length) % SLIDES.length);
  const goNext = () => setSlideIndex((i) => (i + 1) % SLIDES.length);

  return (
    <div className="hero-carousel-bg">
      {SLIDES.map((src, i) => (
        <div
          key={src}
          className="hero-carousel-bg__slide"
          style={{ backgroundImage: `url(${src})`, opacity: i === slideIndex ? 1 : 0 }}
          aria-hidden="true"
        />
      ))}
      <div className="hero-carousel-bg__veil" aria-hidden="true" />
      <p className="hero-carousel-bg__caption" aria-hidden="true" key={slideIndex}>{CAPTIONS[slideIndex % CAPTIONS.length]}</p>
      <button type="button" className="hero-carousel-bg__nav hero-carousel-bg__nav--prev" aria-label="Previous slide" onClick={goPrev}><LeftOutlined /></button>
      <button type="button" className="hero-carousel-bg__nav hero-carousel-bg__nav--next" aria-label="Next slide" onClick={goNext}><RightOutlined /></button>
    </div>
  );
}
