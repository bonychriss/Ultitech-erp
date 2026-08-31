function apiUrl() {
  const fromBoot = window.__ATTENDANCE_PAGE__?.data?.apiUrl;
  if (fromBoot) return fromBoot;
  return 'api/action.php';
}

export async function postAttendanceAction(payload) {
  const res = await fetch(apiUrl(), {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
    body: JSON.stringify(payload),
  });

  let data = null;
  try {
    data = await res.json();
  } catch {
    data = null;
  }

  if (!res.ok || !data) {
    throw new Error((data && data.message) || `Request failed (${res.status})`);
  }
  return data;
}

export function getGeoPosition() {
  return new Promise((resolve) => {
    if (!navigator.geolocation) {
      resolve({ latitude: null, longitude: null });
      return;
    }
    const done = (pos) => {
      resolve({
        latitude: pos.coords.latitude,
        longitude: pos.coords.longitude,
      });
    };
    const fail = () => resolve({ latitude: null, longitude: null });

    navigator.geolocation.getCurrentPosition(
      done,
      (err) => {
        // Retry without high accuracy (common on desktop Wi-Fi).
        if (err && (err.code === 2 || err.code === 3)) {
          navigator.geolocation.getCurrentPosition(
            done,
            fail,
            { enableHighAccuracy: false, timeout: 20000, maximumAge: 60000 }
          );
          return;
        }
        fail();
      },
      { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
    );
  });
}
