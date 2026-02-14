const masterProperties = `/wp-content/themes/kadence-child/map-generator/map-generator-script.js`;

let __resultMasterProperties;

function initResultPropertiesMap() { 
  jQuery.ajax({
    url: masterProperties,
    type: "GET",
    success: function (data) {
      __resultMasterProperties = data;
      console.log(data);

        function generatePropertiesType(idType) {
          jQuery('.holderProperties').append('<div class="items-id" data-id-type="' + idType + '">' + idType + '</div>');
        }
 
       generatePropertiesType('id');

    },
    error: function (xhr, ajaxOptions, thrownError) {
      console.log(xhr.status);
      console.log(thrownError);
    },
  });  
}
initResultPropertiesMap();