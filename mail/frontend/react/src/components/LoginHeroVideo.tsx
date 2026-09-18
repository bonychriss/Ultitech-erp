import { useEffect, useRef } from 'react';

const VIDEO_URL =
  'https://d8j0ntlcm91z4.cloudfront.net/user_38xzZboKViGWJOttwIXH07lWA1P/hf_20260831_232706_43757be4-2250-4f09-8cd7-23aebbf147ad.mp4';

const POSTER_URL =
  'https://images.higgs.ai/?default=1&output=webp&url=https%3A%2F%2Fd8j0ntlcm91z4.cloudfront.net%2Fuser_38xzZboKViGWJOttwIXH07lWA1P%2Fhf_20260831_223518_f11bfa03-4e65-47e1-a4a7-30e42a7a8c2f.png&w=1920&q=85';

/**
 * Full-bleed jungle hero video (no tint/overlay). object-fit: fill spans the section.
 */
export function LoginHeroVideo() {
  const videoRef = useRef<HTMLVideoElement>(null);

  useEffect(() => {
    const video = videoRef.current;
    if (!video) return;
    void video.play().catch(() => {
      // autoplay may be blocked until interaction
    });
  }, []);

  return (
    <div className="mailbox-hero-bg" aria-hidden>
      <video
        ref={videoRef}
        className="mailbox-hero-video"
        autoPlay
        muted
        loop
        playsInline
        preload="auto"
        poster={POSTER_URL}
      >
        <source src={VIDEO_URL} type="video/mp4" />
      </video>
    </div>
  );
}
