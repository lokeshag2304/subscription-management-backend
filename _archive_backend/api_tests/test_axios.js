const axios = require('axios');
const api = axios.create({ baseURL: 'http://127.0.0.1:8000/api' });

console.log(api.getUri({ url: '/secure/Usermanagement/get' }));
console.log(api.getUri({ url: 'secure/Usermanagement/get' }));
