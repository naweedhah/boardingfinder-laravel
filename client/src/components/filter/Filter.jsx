import { useState } from "react";
import "./filter.scss";
import { useSearchParams } from "react-router-dom";

// Main campuses students search around. Coordinates are the campus entrances.
const CAMPUSES = [
  { name: "SLIIT Malabe", lat: 6.9147, lng: 79.9729 },
  { name: "University of Colombo", lat: 6.9022, lng: 79.8607 },
  { name: "University of Moratuwa", lat: 6.7951, lng: 79.9008 },
  { name: "University of Sri Jayewardenepura", lat: 6.8528, lng: 79.9036 },
  { name: "University of Kelaniya", lat: 6.9736, lng: 79.9159 },
  { name: "University of Peradeniya", lat: 7.2549, lng: 80.5974 },
  { name: "NSBM Homagama", lat: 6.8213, lng: 80.0416 },
];

const RADII = [1, 2, 5, 10, 20];

function Filter() {
  const [searchParams, setSearchParams] = useSearchParams();
  const [query, setQuery] = useState({
    preferredTenantGender: searchParams.get("preferredTenantGender") || "",
    city: searchParams.get("city") || "",
    area: searchParams.get("area") || "",
    boardingType: searchParams.get("boardingType") || "",
    minPrice: searchParams.get("minPrice") || "",
    maxPrice: searchParams.get("maxPrice") || "",
    capacity: searchParams.get("capacity") || "",
    lat: searchParams.get("lat") || "",
    lng: searchParams.get("lng") || "",
    radiusKm: searchParams.get("radiusKm") || "5",
  });
  const [nearLabel, setNearLabel] = useState(() => {
    const match = CAMPUSES.find(
      (c) => String(c.lat) === searchParams.get("lat") && String(c.lng) === searchParams.get("lng")
    );
    if (match) return match.name;
    return searchParams.get("lat") ? "my location" : "";
  });
  const [locating, setLocating] = useState(false);
  const [geoError, setGeoError] = useState("");

  const handleChange = (e) => {
    setQuery({
      ...query,
      [e.target.name]: e.target.value,
    });
  };

  const handleCampus = (e) => {
    const campus = CAMPUSES.find((c) => c.name === e.target.value);
    setGeoError("");
    setNearLabel(campus ? campus.name : "");
    setQuery({ ...query, lat: campus ? String(campus.lat) : "", lng: campus ? String(campus.lng) : "" });
  };

  const useMyLocation = () => {
    if (!navigator.geolocation) {
      setGeoError("Your browser cannot share its location.");
      return;
    }
    setLocating(true);
    setGeoError("");
    navigator.geolocation.getCurrentPosition(
      ({ coords }) => {
        setLocating(false);
        setNearLabel("my location");
        setQuery((q) => ({ ...q, lat: coords.latitude.toFixed(5), lng: coords.longitude.toFixed(5) }));
      },
      () => {
        setLocating(false);
        setGeoError("Location permission was denied.");
      },
      { timeout: 10000 }
    );
  };

  const handleFilter = () => {
    // Keep the URL clean: drop empty filters, and the radius when no point is chosen.
    const params = Object.fromEntries(
      Object.entries(query).filter(([key, value]) => value !== "" && (key !== "radiusKm" || query.lat))
    );
    setSearchParams(params);
  };

  return (
    <div className="filter">
      <h1>
        Boarding results for{" "}
        <b>{searchParams.get("city") || searchParams.get("area") || "all areas"}</b>
        {searchParams.get("lat") && (
          <> within <b>{searchParams.get("radiusKm") || 5} km</b> of <b>{nearLabel || "the selected point"}</b></>
        )}
      </h1>
      <div className="top">
        <div className="item">
          <label htmlFor="city">Location</label>
          <input
            type="text"
            id="city"
            name="city"
            placeholder="City"
            onChange={handleChange}
            defaultValue={query.city}
          />
        </div>
        <div className="item">
          <label htmlFor="area">Area</label>
          <input
            type="text"
            id="area"
            name="area"
            placeholder="Area or neighborhood"
            onChange={handleChange}
            defaultValue={query.area}
          />
        </div>
      </div>
      <div className="near">
        <div className="item">
          <label htmlFor="campus">Near campus</label>
          <select
            id="campus"
            name="campus"
            value={CAMPUSES.some((c) => c.name === nearLabel) ? nearLabel : ""}
            onChange={handleCampus}
          >
            <option value="">{nearLabel === "my location" ? "My location" : "Anywhere"}</option>
            {CAMPUSES.map((c) => (
              <option key={c.name} value={c.name}>
                {c.name}
              </option>
            ))}
          </select>
        </div>
        <div className="item">
          <label htmlFor="radiusKm">Within</label>
          <select
            id="radiusKm"
            name="radiusKm"
            value={query.radiusKm}
            onChange={handleChange}
            disabled={!query.lat}
          >
            {RADII.map((r) => (
              <option key={r} value={r}>
                {r} km
              </option>
            ))}
          </select>
        </div>
        <button type="button" className="locate" onClick={useMyLocation} disabled={locating}>
          {locating ? "Locating…" : "Use my location"}
        </button>
        {geoError && <p className="geoError" role="alert">{geoError}</p>}
      </div>
      <div className="bottom">
        <div className="item">
          <label htmlFor="preferredTenantGender">Preferred Gender</label>
          <select
            name="preferredTenantGender"
            id="preferredTenantGender"
            onChange={handleChange}
            defaultValue={query.preferredTenantGender}
          >
            <option value="">any</option>
            <option value="male">Male</option>
            <option value="female">Female</option>
          </select>
        </div>
        <div className="item">
          <label htmlFor="boardingType">Boarding Type</label>
          <select
            name="boardingType"
            id="boardingType"
            onChange={handleChange}
            defaultValue={query.boardingType}
          >
            <option value="">any</option>
            <option value="singleRoom">Single Room</option>
            <option value="sharedRoom">Shared Room</option>
            <option value="annex">Annex</option>
            <option value="hostel">Hostel</option>
            <option value="houseShare">House Share</option>
          </select>
        </div>
        <div className="item">
          <label htmlFor="minPrice">Min Rent</label>
          <input
            type="number"
            id="minPrice"
            name="minPrice"
            placeholder="any"
            onChange={handleChange}
            defaultValue={query.minPrice}
          />
        </div>
        <div className="item">
          <label htmlFor="maxPrice">Max Rent</label>
          <input
            type="number"
            id="maxPrice"
            name="maxPrice"
            placeholder="any"
            onChange={handleChange}
            defaultValue={query.maxPrice}
          />
        </div>
        <div className="item">
          <label htmlFor="capacity">Available Slots</label>
          <input
            type="number"
            id="capacity"
            name="capacity"
            placeholder="any"
            onChange={handleChange}
            defaultValue={query.capacity}
          />
        </div>
        <button onClick={handleFilter}>
          <img src="/search.png" alt="" />
        </button>
      </div>
    </div>
  );
}

export default Filter;
