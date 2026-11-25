import { useState } from "react";
import "./newPostPage.scss";
import ReactQuill from "react-quill";
import "react-quill/dist/quill.snow.css";
import apiRequest from "../../lib/apiRequest";
import UploadWidget from "../../components/uploadWidget/UploadWidget";
import { useNavigate } from "react-router-dom";

function NewPostPage() {
  const [value, setValue] = useState("");
  const [images, setImages] = useState([]);
  const [error, setError] = useState("");
  const [fieldErrors, setFieldErrors] = useState({});

  // Server messages keyed by input name, e.g. "postData.latitude" -> "latitude".
  const fieldError = (name) => {
    const match = Object.entries(fieldErrors).find(([key]) => key.split(".").pop() === name);
    return match ? <span className="fieldError" role="alert">{match[1][0]}</span> : null;
  };

  const navigate = useNavigate();

  const handleSubmit = async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    const inputs = Object.fromEntries(formData);
    const toBoolean = (value) => value === "true";

    setError("");
    setFieldErrors({});
    try {
      const res = await apiRequest.post("/posts", {
        postData: {
          title: inputs.title,
          rent: parseInt(inputs.rent),
          address: inputs.address,
          city: inputs.city,
          area: inputs.area,
          capacity: parseInt(inputs.capacity),
          bathroomCount: parseInt(inputs.bathroomCount),
          boardingType: inputs.boardingType,
          preferredTenantGender: inputs.preferredTenantGender,
          status: inputs.status,
          latitude: inputs.latitude,
          longitude: inputs.longitude,
          images: images,
        },
        postDetail: {
          description: value,
          wifi: toBoolean(inputs.wifi),
          mealsProvided: toBoolean(inputs.mealsProvided),
          kitchenAccess: toBoolean(inputs.kitchenAccess),
          parking: toBoolean(inputs.parking),
          attachedBathroom: toBoolean(inputs.attachedBathroom),
          furnished: toBoolean(inputs.furnished),
          petAllowed: toBoolean(inputs.petAllowed),
          nearestCampus: inputs.nearestCampus,
          distanceToCampus: parseInt(inputs.distanceToCampus),
          distanceToBusStop: parseInt(inputs.distanceToBusStop),
          distanceToTown: parseInt(inputs.distanceToTown),
          rules: inputs.rules,
        },
      });
      navigate("/" + res.data.id);
    } catch (err) {
      const data = err.response?.data;
      setFieldErrors(data?.fields || {});
      setError(
        data?.fields
          ? "Please fix the highlighted fields."
          : data?.message || "Failed to create boarding"
      );
    }
  };

  return (
    <div className="newPostPage">
      <div className="formContainer">
        <h1>Add New Boarding</h1>
        <div className="wrapper">
          <form onSubmit={handleSubmit}>
            <div className="item">
              <label htmlFor="title">Title</label>
              <input id="title" name="title" type="text" required minLength={5} maxLength={60} />
              {fieldError("title")}
            </div>
            <div className="item">
              <label htmlFor="rent">Monthly Rent</label>
              <input id="rent" name="rent" type="number" required min={1} max={1000000} />
              {fieldError("rent")}
            </div>
            <div className="item">
              <label htmlFor="address">Address</label>
              <input id="address" name="address" type="text" required maxLength={255} />
              {fieldError("address")}
            </div>
            <div className="item description">
              <label htmlFor="description">Description</label>
              <ReactQuill theme="snow" onChange={setValue} value={value} />
              {fieldError("description")}
            </div>
            <div className="item">
              <label htmlFor="city">City</label>
              <input id="city" name="city" type="text" required minLength={3} maxLength={100} />
              {fieldError("city")}
            </div>
            <div className="item">
              <label htmlFor="area">Area</label>
              <input id="area" name="area" type="text" />
            </div>
            <div className="item">
              <label htmlFor="capacity">Available Slots</label>
              <input min={1} max={50} id="capacity" name="capacity" type="number" required />
              {fieldError("capacity")}
            </div>
            <div className="item">
              <label htmlFor="bathroomCount">Bathroom Count</label>
              <input min={0} id="bathroomCount" name="bathroomCount" type="number" />
            </div>
            <div className="item">
              <label htmlFor="latitude">Latitude</label>
              <input id="latitude" name="latitude" type="number" step="any" min={5.85} max={9.9} placeholder="6.9147" required />
              {fieldError("latitude")}
            </div>
            <div className="item">
              <label htmlFor="longitude">Longitude</label>
              <input id="longitude" name="longitude" type="number" step="any" min={79.5} max={81.95} placeholder="79.9729" required />
              {fieldError("longitude")}
            </div>
            <div className="item">
              <label htmlFor="boardingType">Boarding Type</label>
              <select name="boardingType">
                <option value="singleRoom">Single Room</option>
                <option value="sharedRoom">Shared Room</option>
                <option value="annex">Annex</option>
                <option value="hostel">Hostel</option>
                <option value="houseShare">House Share</option>
              </select>
            </div>
            <div className="item">
              <label htmlFor="preferredTenantGender">Preferred Tenant</label>
              <select name="preferredTenantGender">
                <option value="any">Any</option>
                <option value="male">Male</option>
                <option value="female">Female</option>
              </select>
            </div>
            <div className="item">
              <label htmlFor="status">Status</label>
              <select name="status">
                <option value="available">Available</option>
                <option value="reserved">Reserved</option>
                <option value="full">Full</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <div className="item">
              <label htmlFor="wifi">Wi-Fi</label>
              <select name="wifi">
                <option value="true">Available</option>
                <option value="false">Not Available</option>
              </select>
            </div>
            <div className="item">
              <label htmlFor="mealsProvided">Meals</label>
              <select name="mealsProvided">
                <option value="true">Provided</option>
                <option value="false">Not Provided</option>
              </select>
            </div>
            <div className="item">
              <label htmlFor="kitchenAccess">Kitchen Access</label>
              <select name="kitchenAccess">
                <option value="true">Available</option>
                <option value="false">Not Available</option>
              </select>
            </div>
            <div className="item">
              <label htmlFor="parking">Parking</label>
              <select name="parking">
                <option value="true">Available</option>
                <option value="false">Not Available</option>
              </select>
            </div>
            <div className="item">
              <label htmlFor="attachedBathroom">Attached Bathroom</label>
              <select name="attachedBathroom">
                <option value="true">Yes</option>
                <option value="false">No</option>
              </select>
            </div>
            <div className="item">
              <label htmlFor="furnished">Furnished</label>
              <select name="furnished">
                <option value="true">Yes</option>
                <option value="false">No</option>
              </select>
            </div>
            <div className="item">
              <label htmlFor="petAllowed">Pets Allowed</label>
              <select name="petAllowed">
                <option value="false">No</option>
                <option value="true">Yes</option>
              </select>
            </div>
            <div className="item">
              <label htmlFor="nearestCampus">Nearest Campus</label>
              <input id="nearestCampus" name="nearestCampus" type="text" />
            </div>
            <div className="item">
              <label htmlFor="distanceToCampus">Distance to Campus (m)</label>
              <input min={0} id="distanceToCampus" name="distanceToCampus" type="number" />
            </div>
            <div className="item">
              <label htmlFor="distanceToBusStop">Distance to Bus Stop (m)</label>
              <input min={0} id="distanceToBusStop" name="distanceToBusStop" type="number" />
            </div>
            <div className="item">
              <label htmlFor="distanceToTown">Distance to Town (m)</label>
              <input min={0} id="distanceToTown" name="distanceToTown" type="number" />
            </div>
            <div className="item">
              <label htmlFor="rules">House Rules</label>
              <input id="rules" name="rules" type="text" placeholder="Curfew, visitors, quiet hours..." />
            </div>
            <button className="sendButton">Add Boarding</button>
            {error && <span className="formError" role="alert">{error}</span>}
          </form>
        </div>
      </div>
      <div className="sideContainer">
        {images.map((image, index) => (
          <img src={image} key={index} alt="" />
        ))}
        <UploadWidget
          uwConfig={{
            multiple: true,
            cloudName: "lamadev",
            uploadPreset: "estate",
            folder: "boardings",
          }}
          setState={setImages}
        />
      </div>
    </div>
  );
}

export default NewPostPage;
