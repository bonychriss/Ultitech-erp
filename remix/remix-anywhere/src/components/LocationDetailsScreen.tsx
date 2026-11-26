import { useState, useEffect } from 'react';
import { X, AlertCircle } from 'lucide-react';
import Markdown from 'react-markdown';
import { motion } from 'motion/react';
import { getClientId } from '../data';

interface LocationDetailsProps {
  data: { image: string; location: string; info: string };
  onClose: () => void;
}

const SAFE_MARKDOWN_ELEMENTS: string[] = [
  'h1',
  'h2',
  'h3',
  'p',
  'strong',
  'em',
  'ul',
  'ol',
  'li',
  'br',
];

export default function LocationDetailsScreen({ data, onClose }: LocationDetailsProps) {
  const info = data.info;
  const [videoUrl, setVideoUrl] = useState<string | null>(null);
  const [isVideoLoading, setIsVideoLoading] = useState(false);
  const [videoError, setVideoError] = useState(false);

  useEffect(() => {
    let isMounted = true;
    let pollInterval: ReturnType<typeof setInterval> | null = null;
    let activeObjectUrl: string | null = null;
    const abortController = new AbortController();

    const clearPolling = () => {
      if (pollInterval !== null) {
        clearInterval(pollInterval);
        pollInterval = null;
      }
    };

    const generateVideo = async () => {
      setIsVideoLoading(true);
      setVideoError(false);
      try {
        const response = await fetch('/api/generate-video', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'x-client-id': getClientId() },
          body: JSON.stringify({
            imageBase64: data.image,
            location: data.location,
            prompt: `A beautiful cinematic panning video of ${data.location}`,
          }),
          signal: abortController.signal,
        });

        const contentType = response.headers.get('content-type') || '';
        if (!response.ok || !contentType.includes('application/json')) {
          throw new Error(`Video generation failed (${response.status})`);
        }

        const result = await response.json();
        if (!result?.success || !result?.fileId) {
          throw new Error(result?.error || 'Failed to start video generation');
        }

        const fileId = String(result.fileId);
        let polls = 0;
        let transientErrors = 0;
        const maxPolls = 48;

        pollInterval = setInterval(async () => {
          polls++;
          if (polls > maxPolls || abortController.signal.aborted) {
            clearPolling();
            if (isMounted) {
              setVideoError(true);
              setIsVideoLoading(false);
            }
            return;
          }
          try {
            const statusRes = await fetch('/api/video-status', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'x-client-id': getClientId() },
              body: JSON.stringify({ fileId }),
              signal: abortController.signal,
            });

            if (statusRes.status >= 400 && statusRes.status < 500 && statusRes.status !== 429) {
              clearPolling();
              if (isMounted) {
                setVideoError(true);
                setIsVideoLoading(false);
              }
              return;
            }

            const statusType = statusRes.headers.get('content-type') || '';
            if (!statusRes.ok || !statusType.includes('application/json')) {
              transientErrors += 1;
              if (transientErrors >= 4) {
                clearPolling();
                if (isMounted) {
                  setVideoError(true);
                  setIsVideoLoading(false);
                }
              }
              return;
            }

            transientErrors = 0;
            const statusResult = await statusRes.json();

            if (statusResult.done && isMounted) {
              clearPolling();
              if (statusResult.state === 'FAILED') {
                setVideoError(true);
              } else {
                const dlRes = await fetch(`/api/video-download?fileId=${encodeURIComponent(fileId)}`, {
                  headers: { 'x-client-id': getClientId() },
                  signal: abortController.signal,
                });
                if (!dlRes.ok) {
                  setVideoError(true);
                } else {
                  const blob = await dlRes.blob();
                  if (!isMounted) return;
                  activeObjectUrl = URL.createObjectURL(blob);
                  setVideoUrl(activeObjectUrl);
                }
              }
              if (isMounted) setIsVideoLoading(false);
            }
          } catch (e) {
            if ((e as any)?.name === 'AbortError' || !isMounted) return;
            transientErrors += 1;
            if (transientErrors >= 4) {
              console.warn('Video status polling stopped after repeated errors:', e);
              clearPolling();
              setVideoError(true);
              setIsVideoLoading(false);
            }
          }
        }, 5000);
      } catch (e) {
        if ((e as any)?.name === 'AbortError' || !isMounted) return;
        console.warn('Video generation unavailable for location:', e);
        setVideoError(true);
        setIsVideoLoading(false);
      }
    };

    if (data.image && data.image.startsWith('data:image')) {
      void generateVideo();
    }

    return () => {
      isMounted = false;
      abortController.abort();
      clearPolling();
      if (activeObjectUrl) URL.revokeObjectURL(activeObjectUrl);
    };
  }, [data.image, data.location]);

  return (
    <motion.div
      initial={{ opacity: 0 }}
      animate={{ opacity: 1 }}
      exit={{ opacity: 0 }}
      transition={{ duration: 0.5, ease: [0.16, 1, 0.3, 1] }}
      className="absolute inset-0 z-50 flex items-center justify-center p-6 sm:p-12"
    >
      {/* Background click listener */}
      <div className="absolute inset-0 bg-transparent" onClick={onClose} />

      <motion.div
        initial={{ scale: 0.8, opacity: 0, y: 20 }}
        animate={{ scale: 1, opacity: 1, y: 0 }}
        exit={{ scale: 0.8, opacity: 0, y: 20 }}
        transition={{ duration: 0.6, ease: [0.16, 1, 0.3, 1] }}
        className="bg-white shadow-[0_0_80px_rgba(0,0,0,0.15)] flex flex-col md:flex-row w-full max-w-6xl h-full max-h-[800px] relative rounded-none z-10 overflow-hidden"
      >
        <button
          onClick={onClose}
          className="absolute top-6 right-6 p-3 bg-white hover:bg-gray-100 transition-colors z-20 shadow-sm border border-gray-200 rounded-full flex items-center justify-center"
        >
          <X className="w-6 h-6 text-gray-900" />
        </button>

        <div className="hidden md:block w-full md:w-[45%] h-[40vh] md:h-full bg-gray-100 flex-shrink-0 relative overflow-hidden group">
          {videoUrl ? (
            <video
              src={videoUrl}
              autoPlay
              loop
              muted
              playsInline
              onError={() => {
                setVideoUrl(null);
                setVideoError(true);
              }}
              className="w-full h-full object-cover"
            />
          ) : (
            <>
              <img
                src={data.image}
                alt={data.location}
                className="w-full h-full object-cover transition-transform duration-[20s] ease-linear hover:scale-110"
              />
              {videoError && (
                <div className="absolute inset-0 bg-black/40 flex flex-col items-center justify-center text-white backdrop-blur-sm">
                  <AlertCircle className="w-8 h-8 mb-4 text-red-400" />
                  <p className="font-sans font-medium tracking-tight text-sm text-center px-6">
                    Unable to generate video for this location.
                  </p>
                </div>
              )}
            </>
          )}
        </div>

        <div className="w-full h-full md:w-[55%] flex flex-col p-6 sm:p-8 md:p-16 overflow-y-auto bg-white justify-center">
          <div className="font-sans text-gray-600 text-base md:text-lg space-y-6 [&>h1]:text-3xl [&>h1]:md:text-5xl [&>h1]:font-bold [&>h1]:tracking-tighter [&>h1]:text-gray-900 [&>h1]:mb-8 [&>h1]:leading-tight [&>h1]:break-words [&>h1]:text-balance [&>p]:leading-relaxed [&>p]:mb-4">
            <Markdown allowedElements={SAFE_MARKDOWN_ELEMENTS} unwrapDisallowed>
              {info.split('\n').find((l) => l.trim().startsWith('#')) || `# ${data.location}`}
            </Markdown>

            {videoUrl || !isVideoLoading ? (
              <Markdown allowedElements={SAFE_MARKDOWN_ELEMENTS} unwrapDisallowed>
                {info
                  .split('\n')
                  .filter((l) => !l.trim().startsWith('#'))
                  .join('\n')}
              </Markdown>
            ) : (
              <div className="space-y-6 w-full flex flex-col justify-center mt-8">
                <div className="h-4 bg-gray-200 w-full rounded-sm"></div>
                <div className="h-4 bg-gray-200 w-11/12 rounded-sm"></div>
                <div className="h-4 bg-gray-200 w-10/12 rounded-sm"></div>
                <div className="h-4 bg-gray-200 w-full rounded-sm mt-4"></div>
                <div className="h-4 bg-gray-200 w-9/12 rounded-sm"></div>
              </div>
            )}
          </div>
        </div>
      </motion.div>
    </motion.div>
  );
}
