(() => {
    const realFetch = window.fetch
    window.fetch = async function(resource, options) {
        const url = String(resource)
        if (URL.canParse(url)) {
            resource = MY_SERVER + '/' + url
        }
        return await realFetch(resource, options)
    }
})()