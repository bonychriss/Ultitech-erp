// A curated list of stable Picsum photo IDs to ensure consistent loading and a nice variety.
// These represent high-quality portrait/architecture/nature photography.
export const PHOTO_IDS = [
  10, 11, 12, 13, 14, 15, 16, 17, 28, 29, 37, 39, 43, 49, 50, 
  54, 57, 58, 59, 61, 62, 63, 64, 65, 68, 69, 71, 74, 82, 87, 
  91, 111, 112, 113, 114, 115, 119, 122, 129, 133, 134, 136, 139, 142, 146, 
  152, 153, 154, 158, 161, 163, 164, 169, 175, 177, 180, 185, 188, 191, 196
];

export const GLOBE_RADIUS = 6.0;
export const CARD_WIDTH = 1.8;
export const CARD_HEIGHT = 2.4;
export const TOTAL_CARDS = 48;

export const LOCATIONS = [
  "Eiffel Tower, Paris",
  "Taj Mahal, India",
  "Statue of Liberty, New York",
  "Great Wall of China",
  "Machu Picchu, Peru",
  "Colosseum, Rome",
  "Pyramids of Giza, Egypt",
  "Sydney Opera House",
  "Mount Fuji, Japan",
  "Santorini, Greece",
  "Stonehenge, UK",
  "Petra, Jordan",
  "Burj Khalifa, Dubai",
  "Niagara Falls, Canada",
  "Mount Everest, Himalayas",
  "Golden Gate Bridge, San Francisco",
  "Acropolis of Athens",
  "Angkor Wat, Cambodia",
  "Sagrada Familia, Barcelona",
  "Venice Canals, Italy",
  "Victoria Falls, Zambia",
  "Galapagos Islands",
  "Easter Island statues",
  "Chichen Itza, Mexico",
  "Yellowstone Grand Prismatic Spring",
  "Aurora Borealis in Iceland",
  "Serengeti National Park, Tanzania",
  "Banff National Park, Canada",
  "Salar de Uyuni, Bolivia",
  "Bora Bora overwater bungalows",
  "Maldives beaches",
  "Christ the Redeemer, Brazil",
  "Table Mountain, South Africa",
  "Neuschwanstein Castle, Germany",
  "St. Basil's Cathedral, Moscow",
  "Forbidden City, Beijing",
  "Halong Bay, Vietnam",
  "Times Square, New York at night",
  "Grand Canyon, Arizona",
  "Big Ben, London",
  "Louvre Museum pyramid, Paris",
  "Blue Lagoon, Iceland",
  "Mount Kilimanjaro",
  "Cinque Terre, Italy",
  "Lake Como, Italy",
  "The Alhambra, Spain",
  "Cappadocia hot air balloons, Turkey",
  "Antelope Canyon, Arizona",
];

let fallbackClientId = "";
const VALID_CLIENT_ID_RE = /^[a-zA-Z0-9._-]{8,128}$/;

function createClientId(): string {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return crypto.randomUUID();
  }
  return Math.random().toString(36).slice(2) + "_" + Date.now().toString(36) + "_cid";
}

export function getClientId(): string {
  try {
    const storage = window.sessionStorage;
    const existing = storage.getItem("__aistudio_client_id");
    if (existing && VALID_CLIENT_ID_RE.test(existing)) return existing;
    const created = createClientId();
    storage.setItem("__aistudio_client_id", created);
    return created;
  } catch {
    if (!fallbackClientId || !VALID_CLIENT_ID_RE.test(fallbackClientId)) {
      fallbackClientId = createClientId();
    }
    return fallbackClientId;
  }
}
