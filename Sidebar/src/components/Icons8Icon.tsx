import { cn } from '@/lib/utils'

const iconModules = import.meta.glob('../assets/icons8/*.png', {
  eager: true,
  import: 'default',
}) as Record<string, string>

function resolveIconSrc(name: string): string {
  const key = `../assets/icons8/${name}.png`
  return iconModules[key] || iconModules[`../assets/icons8/document.png`] || ''
}

/** Icons8 static color icons (https://icons8.com/icons/set/filter--static). */
export function Icons8Icon({
  name,
  alt = '',
  className,
}: {
  name: string
  alt?: string
  className?: string
}) {
  return (
    <img
      src={resolveIconSrc(name)}
      alt={alt || name}
      width={16}
      height={16}
      className={cn('size-4 shrink-0 object-contain', className)}
      draggable={false}
    />
  )
}

export const ICONS8_CDN_STYLE = 'color' as const
