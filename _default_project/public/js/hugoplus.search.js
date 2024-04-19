
document.getElementById("search_input").onkeyup = function(e)
	{ 
		if(e.key === 'Enter' || e.keyCode === 13)
		{
			if(2 < this.value.length)
			{
				console.log(this.value.length);
				executeSearch(this.value);
			}
			else
			{
				let element = document.getElementById("search_input");
				element.value = "";
				element.placeholder = document.getElementById("search_error").value;
			}
		}		  
	}

function executeSearch(term)
{
	var httpRequest = new XMLHttpRequest();
	httpRequest.onreadystatechange = function()
	{
		if (httpRequest.readyState === 4)
		{
			if (httpRequest.status === 200)
			{
				if(null != httpRequest.responseText)
				{
					document.getElementById("main_section").innerHTML = httpRequest.responseText;
				}
				else
				{
					console.log("error empty response");
				}
  			}
			else
			{
				console.log("error loading response");
			}
		}
	};
	httpRequest.open('GET', "/hugoplus.search/?term=" + term);
	httpRequest.send(); 
}

