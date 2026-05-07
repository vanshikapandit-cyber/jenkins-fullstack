const express = require('express');
const app = express();
app.get('/',(req,res)=>
{
    res.send("hello from client side ");
});
app.listen(3000, ()=>
{
    console.log("Server is running at 3000 port 😊");
});
console.log("Hi! there");


